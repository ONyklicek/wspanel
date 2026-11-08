package ops

import (
	"bufio"
	"context"
	"encoding/json"
	"io"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"syscall"

	"wspanel/agent/internal/fsx"
)

// Traffic stats from the edge's per-site JSON access log. Each call reads
// what was added since the last one (offset kept per site, reset when the
// log rotates) and returns it summed up by hour; the panel adds the hours up.

type trafficBucket struct {
	Hour     int64            `json:"hour"` // unix time, start of the hour (UTC)
	Requests int64            `json:"requests"`
	Bytes    int64            `json:"bytes"`
	S2xx     int64            `json:"s2xx"`
	S3xx     int64            `json:"s3xx"`
	S4xx     int64            `json:"s4xx"`
	S5xx     int64            `json:"s5xx"`
	Bots     int64            `json:"bots"`
	TotalMs  int64            `json:"total_ms"` // sum of response times
	Paths    map[string]int64 `json:"paths"`
	IPs      map[string]int64 `json:"ips"`
	Referers map[string]int64 `json:"referers"` // other sites linking here (host)
}

type trafficCursor struct {
	Inode  uint64 `json:"inode"`
	Offset int64  `json:"offset"`
}

var botRe = regexp.MustCompile(`(?i)bot|crawl|spider|slurp|curl|wget|python-requests|go-http-client|headless|scrapy`)

const (
	trafficMaxLines = 200000
	trafficTop      = 25
)

func trafficCursorPath(env *Env, domain string) string {
	return filepath.Join(env.Cfg.StateDir, "traffic", domain+".json")
}

func siteTraffic(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Domain string `json:"domain"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	_, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}
	f, err := os.Open(l.AccessLog)
	if os.IsNotExist(err) {
		return map[string]any{"domain": p.Domain, "buckets": []trafficBucket{}, "more": false}, nil
	}
	if err != nil {
		return nil, err
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return nil, err
	}
	var inode uint64
	if st, ok := fi.Sys().(*syscall.Stat_t); ok {
		inode = uint64(st.Ino)
	}
	var cur trafficCursor
	if b, err := os.ReadFile(trafficCursorPath(env, p.Domain)); err == nil {
		_ = json.Unmarshal(b, &cur)
	}
	if cur.Inode != inode || cur.Offset > fi.Size() { // rotated or truncated: from the start
		cur = trafficCursor{Inode: inode}
	}
	if _, err := f.Seek(cur.Offset, io.SeekStart); err != nil {
		return nil, err
	}
	buckets, read, more := aggregateAccessLog(bufio.NewReaderSize(f, 1<<16), p.Domain)
	cur.Offset += read
	b, _ := json.Marshal(cur)
	if err := env.FS.MkdirAll(filepath.Dir(trafficCursorPath(env, p.Domain)), 0o700, fsx.Root); err != nil {
		return nil, err
	}
	if err := env.FS.WriteFile(trafficCursorPath(env, p.Domain), b, 0o600, fsx.Root); err != nil {
		return nil, err
	}
	return map[string]any{"domain": p.Domain, "buckets": buckets, "more": more}, nil
}

// aggregateAccessLog sums complete lines (a line still being written stays
// for the next call). Returns the buckets, the bytes consumed and whether
// the line limit stopped it early.
func aggregateAccessLog(r *bufio.Reader, domain string) ([]trafficBucket, int64, bool) {
	byHour := map[int64]*trafficBucket{}
	var read int64
	lines := 0
	for lines < trafficMaxLines {
		line, err := r.ReadBytes('\n')
		if err != nil { // EOF: an incomplete last line is left for later
			break
		}
		read += int64(len(line))
		lines++
		var e struct {
			Ts       float64 `json:"ts"`
			Status   int     `json:"status"`
			Size     int64   `json:"size"`
			Duration float64 `json:"duration"`
			Request  struct {
				ClientIP string              `json:"client_ip"`
				RemoteIP string              `json:"remote_ip"`
				URI      string              `json:"uri"`
				Headers  map[string][]string `json:"headers"`
			} `json:"request"`
		}
		if json.Unmarshal(line, &e) != nil || e.Ts == 0 {
			continue
		}
		hour := int64(e.Ts) / 3600 * 3600
		bk := byHour[hour]
		if bk == nil {
			bk = &trafficBucket{Hour: hour, Paths: map[string]int64{}, IPs: map[string]int64{}, Referers: map[string]int64{}}
			byHour[hour] = bk
		}
		bk.Requests++
		bk.Bytes += e.Size
		bk.TotalMs += int64(e.Duration * 1000)
		switch {
		case e.Status >= 500:
			bk.S5xx++
		case e.Status >= 400:
			bk.S4xx++
		case e.Status >= 300:
			bk.S3xx++
		default:
			bk.S2xx++
		}
		if ua := first(e.Request.Headers["User-Agent"]); ua == "" || botRe.MatchString(ua) {
			bk.Bots++
		}
		path, _, _ := strings.Cut(e.Request.URI, "?")
		if len(path) > 200 {
			path = path[:200]
		}
		bk.Paths[path]++
		ip := e.Request.ClientIP
		if ip == "" {
			ip = e.Request.RemoteIP
		}
		bk.IPs[ip]++
		if ref := first(e.Request.Headers["Referer"]); ref != "" {
			if u, err := url.Parse(ref); err == nil && u.Host != "" && !strings.EqualFold(u.Hostname(), domain) {
				bk.Referers[strings.ToLower(u.Hostname())]++
			}
		}
	}
	out := make([]trafficBucket, 0, len(byHour))
	for _, bk := range byHour {
		bk.Paths, bk.IPs, bk.Referers = topN(bk.Paths), topN(bk.IPs), topN(bk.Referers)
		out = append(out, *bk)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Hour < out[j].Hour })
	return out, read, lines >= trafficMaxLines
}

func first(v []string) string {
	if len(v) == 0 {
		return ""
	}
	return v[0]
}

// topN keeps the trafficTop largest counts.
func topN(m map[string]int64) map[string]int64 {
	if len(m) <= trafficTop {
		return m
	}
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Slice(keys, func(i, j int) bool { return m[keys[i]] > m[keys[j]] || (m[keys[i]] == m[keys[j]] && keys[i] < keys[j]) })
	out := make(map[string]int64, trafficTop)
	for _, k := range keys[:trafficTop] {
		out[k] = m[k]
	}
	return out
}

// Package s3 is a small client for S3-compatible object storage (AWS S3,
// Cloudflare R2, Backblaze B2, Wasabi, MinIO, ...): put (multipart for large
// files), get, delete and list, signed with AWS Signature Version 4. Only the
// standard library, so the agent keeps no third-party code in its root path.
package s3

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/xml"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"sort"
	"strconv"
	"strings"
	"time"
)

// PartSize is the size of one multipart part; files up to it go in one PUT.
const PartSize = 64 << 20

const emptyHash = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"

type Config struct {
	Endpoint  string // https://s3.eu-central-1.amazonaws.com, https://<account>.r2.cloudflarestorage.com, ...
	Region    string // "auto" for R2; us-east-1 when the provider does not care
	Bucket    string
	AccessKey string
	SecretKey string
	PathStyle bool // https://endpoint/bucket/key instead of https://bucket.endpoint/key
}

type Client struct {
	cfg  Config
	base *url.URL
	HTTP *http.Client
	Now  func() time.Time
}

type Object struct {
	Key          string
	Size         int64
	LastModified time.Time
}

// Error is an answer of the storage with an error code.
type Error struct {
	Status  int
	Code    string
	Message string
}

func (e *Error) Error() string {
	if e.Code == "" {
		return fmt.Sprintf("storage answered HTTP %d", e.Status)
	}
	return fmt.Sprintf("%s: %s (HTTP %d)", e.Code, e.Message, e.Status)
}

func New(cfg Config) (*Client, error) {
	u, err := url.Parse(strings.TrimRight(cfg.Endpoint, "/"))
	if err != nil || u.Host == "" || (u.Scheme != "https" && u.Scheme != "http") || u.User != nil || (u.Path != "" && u.Path != "/") || u.RawQuery != "" {
		return nil, errors.New("endpoint: an http(s) address without a path, e.g. https://s3.eu-central-1.amazonaws.com")
	}
	u.Path = ""
	if cfg.Region == "" {
		cfg.Region = "us-east-1"
	}
	return &Client{cfg: cfg, base: u, HTTP: &http.Client{Timeout: 0}, Now: time.Now}, nil
}

// objectURL builds the address of a key (or of the bucket for key "").
func (c *Client) objectURL(key string, query url.Values) *url.URL {
	u := *c.base
	if c.cfg.PathStyle {
		u.Path = "/" + c.cfg.Bucket
		if key != "" {
			u.Path += "/" + key
		}
	} else {
		u.Host = c.cfg.Bucket + "." + u.Host
		u.Path = "/" + key
	}
	u.RawPath = encodePath(u.Path)
	u.RawQuery = encodeQuery(query)
	return &u
}

// encodePath escapes every segment as SigV4 wants (RFC 3986 unreserved kept).
func encodePath(p string) string {
	parts := strings.Split(p, "/")
	for i, s := range parts {
		parts[i] = uriEncode(s)
	}
	return strings.Join(parts, "/")
}

func uriEncode(s string) string {
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		c := s[i]
		if c >= 'A' && c <= 'Z' || c >= 'a' && c <= 'z' || c >= '0' && c <= '9' || c == '-' || c == '_' || c == '.' || c == '~' {
			b.WriteByte(c)
		} else {
			fmt.Fprintf(&b, "%%%02X", c)
		}
	}
	return b.String()
}

func encodeQuery(q url.Values) string {
	if len(q) == 0 {
		return ""
	}
	keys := make([]string, 0, len(q))
	for k := range q {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	var parts []string
	for _, k := range keys {
		vs := append([]string(nil), q[k]...)
		sort.Strings(vs)
		for _, v := range vs {
			parts = append(parts, uriEncode(k)+"="+uriEncode(v))
		}
	}
	return strings.Join(parts, "&")
}

func hmacSHA256(key []byte, data string) []byte {
	h := hmac.New(sha256.New, key)
	h.Write([]byte(data))
	return h.Sum(nil)
}

// sign adds the SigV4 Authorization header. payloadHash is the hex SHA-256 of
// the body or UNSIGNED-PAYLOAD (allowed over HTTPS by S3 and its clones).
func (c *Client) sign(req *http.Request, payloadHash string) {
	now := c.Now().UTC()
	amzDate := now.Format("20060102T150405Z")
	day := now.Format("20060102")
	req.Header.Set("x-amz-date", amzDate)
	req.Header.Set("x-amz-content-sha256", payloadHash)

	headers := map[string]string{"host": req.URL.Host}
	for k, v := range req.Header {
		lk := strings.ToLower(k)
		if strings.HasPrefix(lk, "x-amz-") || lk == "range" || lk == "content-md5" || lk == "content-type" {
			headers[lk] = strings.TrimSpace(strings.Join(v, ","))
		}
	}
	names := make([]string, 0, len(headers))
	for k := range headers {
		names = append(names, k)
	}
	sort.Strings(names)
	var canonHeaders strings.Builder
	for _, k := range names {
		canonHeaders.WriteString(k + ":" + headers[k] + "\n")
	}
	signed := strings.Join(names, ";")
	canonical := strings.Join([]string{
		req.Method,
		req.URL.EscapedPath(),
		req.URL.RawQuery,
		canonHeaders.String(),
		signed,
		payloadHash,
	}, "\n")
	scope := day + "/" + c.cfg.Region + "/s3/aws4_request"
	sum := sha256.Sum256([]byte(canonical))
	toSign := "AWS4-HMAC-SHA256\n" + amzDate + "\n" + scope + "\n" + hex.EncodeToString(sum[:])
	key := hmacSHA256([]byte("AWS4"+c.cfg.SecretKey), day)
	key = hmacSHA256(key, c.cfg.Region)
	key = hmacSHA256(key, "s3")
	key = hmacSHA256(key, "aws4_request")
	sig := hex.EncodeToString(hmacSHA256(key, toSign))
	req.Header.Set("Authorization", "AWS4-HMAC-SHA256 Credential="+c.cfg.AccessKey+"/"+scope+", SignedHeaders="+signed+", Signature="+sig)
}

// do sends one signed request; body is replayed from the ReaderAt section
// on a retry (network errors, 5xx, 429).
func (c *Client) do(ctx context.Context, method, key string, query url.Values, body func() io.Reader, size int64, payloadHash string, header http.Header) (*http.Response, error) {
	var last error
	for attempt := 0; attempt < 3; attempt++ {
		if attempt > 0 {
			select {
			case <-ctx.Done():
				return nil, ctx.Err()
			case <-time.After(time.Duration(attempt) * 2 * time.Second):
			}
		}
		var r io.Reader
		if body != nil {
			r = body()
		}
		req, err := http.NewRequestWithContext(ctx, method, c.objectURL(key, query).String(), r)
		if err != nil {
			return nil, err
		}
		req.URL = c.objectURL(key, query) // keep our exact escaping
		req.ContentLength = size
		if body == nil {
			req.Body = nil
			req.ContentLength = 0
		}
		for k, v := range header {
			req.Header[k] = v
		}
		c.sign(req, payloadHash)
		resp, err := c.HTTP.Do(req)
		if err != nil {
			last = err
			continue
		}
		if resp.StatusCode >= 500 || resp.StatusCode == http.StatusTooManyRequests {
			last = readError(resp)
			continue
		}
		if resp.StatusCode >= 300 {
			return nil, readError(resp)
		}
		return resp, nil
	}
	return nil, last
}

func readError(resp *http.Response) error {
	defer resp.Body.Close()
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 64<<10))
	e := &Error{Status: resp.StatusCode}
	var x struct {
		Code    string `xml:"Code"`
		Message string `xml:"Message"`
	}
	if xml.Unmarshal(b, &x) == nil {
		e.Code, e.Message = x.Code, x.Message
	}
	return e
}

// Put uploads size bytes of r under key: one PUT up to PartSize, multipart
// above it (aborted again if a part fails).
func (c *Client) Put(ctx context.Context, key string, r io.ReaderAt, size int64) error {
	if size <= PartSize {
		resp, err := c.do(ctx, http.MethodPut, key, nil, func() io.Reader { return io.NewSectionReader(r, 0, size) }, size, "UNSIGNED-PAYLOAD", nil)
		if err != nil {
			return err
		}
		resp.Body.Close()
		return nil
	}
	resp, err := c.do(ctx, http.MethodPost, key, url.Values{"uploads": {""}}, nil, 0, emptyHash, nil)
	if err != nil {
		return err
	}
	var start struct {
		UploadID string `xml:"UploadId"`
	}
	err = xml.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(&start)
	resp.Body.Close()
	if err != nil || start.UploadID == "" {
		return errors.New("storage did not start a multipart upload")
	}
	id := start.UploadID
	type part struct {
		Number int    `xml:"PartNumber"`
		ETag   string `xml:"ETag"`
	}
	var parts []part
	err = func() error {
		for n, off := 1, int64(0); off < size; n, off = n+1, off+PartSize {
			length := min(PartSize, size-off)
			o := off
			resp, err := c.do(ctx, http.MethodPut, key, url.Values{"partNumber": {strconv.Itoa(n)}, "uploadId": {id}},
				func() io.Reader { return io.NewSectionReader(r, o, length) }, length, "UNSIGNED-PAYLOAD", nil)
			if err != nil {
				return fmt.Errorf("part %d: %w", n, err)
			}
			resp.Body.Close()
			parts = append(parts, part{Number: n, ETag: resp.Header.Get("ETag")})
		}
		doc, _ := xml.Marshal(struct {
			XMLName xml.Name `xml:"CompleteMultipartUpload"`
			Parts   []part   `xml:"Part"`
		}{Parts: parts})
		sum := sha256.Sum256(doc)
		resp, err := c.do(ctx, http.MethodPost, key, url.Values{"uploadId": {id}}, func() io.Reader { return bytes.NewReader(doc) }, int64(len(doc)), hex.EncodeToString(sum[:]), nil)
		if err != nil {
			return err
		}
		defer resp.Body.Close()
		// S3 may answer 200 with an error document.
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
		if bytes.Contains(b, []byte("<Error>")) {
			return &Error{Status: 200, Code: "CompleteFailed", Message: string(b)}
		}
		return nil
	}()
	if err != nil {
		if resp, aerr := c.do(context.WithoutCancel(ctx), http.MethodDelete, key, url.Values{"uploadId": {id}}, nil, 0, emptyHash, nil); aerr == nil {
			resp.Body.Close()
		}
		return err
	}
	return nil
}

// Get opens an object for reading.
func (c *Client) Get(ctx context.Context, key string) (io.ReadCloser, int64, error) {
	resp, err := c.do(ctx, http.MethodGet, key, nil, nil, 0, emptyHash, nil)
	if err != nil {
		return nil, 0, err
	}
	return resp.Body, resp.ContentLength, nil
}

func (c *Client) Delete(ctx context.Context, key string) error {
	resp, err := c.do(ctx, http.MethodDelete, key, nil, nil, 0, emptyHash, nil)
	if err != nil {
		return err
	}
	resp.Body.Close()
	return nil
}

// List returns every object under prefix (following continuation tokens).
func (c *Client) List(ctx context.Context, prefix string) ([]Object, error) {
	var out []Object
	token := ""
	for page := 0; page < 1000; page++ {
		q := url.Values{"list-type": {"2"}, "prefix": {prefix}}
		if token != "" {
			q.Set("continuation-token", token)
		}
		resp, err := c.do(ctx, http.MethodGet, "", q, nil, 0, emptyHash, nil)
		if err != nil {
			return nil, err
		}
		var res struct {
			Contents []struct {
				Key          string `xml:"Key"`
				Size         int64  `xml:"Size"`
				LastModified string `xml:"LastModified"`
			} `xml:"Contents"`
			IsTruncated bool   `xml:"IsTruncated"`
			NextToken   string `xml:"NextContinuationToken"`
		}
		err = xml.NewDecoder(io.LimitReader(resp.Body, 32<<20)).Decode(&res)
		resp.Body.Close()
		if err != nil {
			return nil, fmt.Errorf("unreadable listing: %w", err)
		}
		for _, o := range res.Contents {
			t, _ := time.Parse(time.RFC3339, o.LastModified)
			out = append(out, Object{Key: o.Key, Size: o.Size, LastModified: t})
		}
		if !res.IsTruncated || res.NextToken == "" {
			return out, nil
		}
		token = res.NextToken
	}
	return out, nil
}

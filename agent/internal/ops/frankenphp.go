package ops

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"time"

	"wspanel/agent/internal/protocol"
)

// FrankenPHP ships as one static binary with PHP and OpenSSL inside; it does
// not update itself. frankenphp.update replaces it with a release from
// GitHub, verified against the sha256 digest GitHub publishes for the asset,
// and rolls back if the proxy or web tier do not come up again.

var frankenVersionRe = regexp.MustCompile(`^v\d{1,3}\.\d{1,3}\.\d{1,3}$`)

type releaseAsset struct {
	URL    string
	SHA256 string
}

// fetchRelease and download are variables so tests can replace the network.
var fetchRelease = func(ctx context.Context, version, arch string) (releaseAsset, error) {
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, "https://api.github.com/repos/php/frankenphp/releases/tags/"+version, nil)
	req.Header.Set("Accept", "application/vnd.github+json")
	req.Header.Set("User-Agent", "wspanel-agent")
	resp, err := (&http.Client{Timeout: 20 * time.Second}).Do(req)
	if err != nil {
		return releaseAsset{}, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return releaseAsset{}, fmt.Errorf("GitHub: release %s: HTTP %d", version, resp.StatusCode)
	}
	var rel struct {
		Assets []struct {
			Name   string `json:"name"`
			URL    string `json:"browser_download_url"`
			Digest string `json:"digest"`
		} `json:"assets"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 4<<20)).Decode(&rel); err != nil {
		return releaseAsset{}, err
	}
	want := "frankenphp-linux-" + arch
	for _, a := range rel.Assets {
		if a.Name == want {
			sum, ok := strings.CutPrefix(a.Digest, "sha256:")
			if !ok || len(sum) != 64 {
				return releaseAsset{}, fmt.Errorf("GitHub publishes no sha256 digest for %s; refusing to install it unverified", want)
			}
			if !strings.HasPrefix(a.URL, "https://github.com/php/frankenphp/releases/download/") {
				return releaseAsset{}, fmt.Errorf("unexpected download URL %q", a.URL)
			}
			return releaseAsset{URL: a.URL, SHA256: sum}, nil
		}
	}
	return releaseAsset{}, fmt.Errorf("release %s has no asset %s", version, want)
}

var download = func(ctx context.Context, url string, w io.Writer) error {
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	req.Header.Set("User-Agent", "wspanel-agent")
	resp, err := (&http.Client{Timeout: 10 * time.Minute}).Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("download: HTTP %d", resp.StatusCode)
	}
	_, err = io.Copy(w, io.LimitReader(resp.Body, 512<<20))
	return err
}

func frankenArch() string {
	if runtime.GOARCH == "arm64" {
		return "aarch64"
	}
	return "x86_64"
}

var cronServiceRe = regexp.MustCompile(`-cron-\d+\.service$`)

// frankenUnits: long-running units on the FrankenPHP binary that must restart:
// web tier and edge, worker-mode apps and their workers (php-cli). Scheduled
// tasks are oneshots started by timers: restarting one would just run the
// task; they pick up the new binary on their next run anyway.
func frankenUnits(env *Env) []string {
	units := []string{env.Cfg.WebUnit, env.Cfg.ProxyUnit}
	files, _ := filepath.Glob(filepath.Join(env.Cfg.SystemdDir, "wspanel-*.service"))
	for _, f := range files {
		if cronServiceRe.MatchString(f) {
			continue
		}
		if b, err := os.ReadFile(f); err == nil && strings.Contains(string(b), "ExecStart="+env.Cfg.FrankenPHPBin+" ") {
			units = append(units, filepath.Base(f))
		}
	}
	return units
}

func frankenphpUpdate(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Version string `json:"version"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if !frankenVersionRe.MatchString(p.Version) {
		return nil, protocol.Invalid("version: e.g. v1.14.0")
	}
	before := "unknown"
	if res, err := env.Exec.Run(ctx, "frankenphp", "version"); err == nil {
		before = strings.TrimSpace(res.Stdout)
	}
	if strings.Contains(before, "FrankenPHP "+p.Version+" ") {
		return map[string]any{"version": p.Version, "changed": false}, nil
	}

	asset, err := fetchRelease(ctx, p.Version, frankenArch())
	if err != nil {
		return nil, err
	}
	bin := env.Cfg.FrankenPHPBin
	newPath, prevPath := bin+".new", bin+".prev"
	f, err := os.OpenFile(newPath, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o700)
	if err != nil {
		return nil, err
	}
	h := sha256.New()
	err = download(ctx, asset.URL, io.MultiWriter(f, h))
	f.Close()
	if err != nil {
		os.Remove(newPath)
		return nil, err
	}
	if got := hex.EncodeToString(h.Sum(nil)); got != asset.SHA256 {
		os.Remove(newPath)
		return nil, fmt.Errorf("checksum mismatch: got %s, GitHub says %s; nothing changed", got, asset.SHA256)
	}
	if err := os.Chmod(newPath, 0o755); err != nil {
		return nil, err
	}
	res, err := env.Exec.Run(ctx, "frankenphp-new", "version")
	if err != nil || !strings.Contains(res.Stdout, p.Version) {
		os.Remove(newPath)
		return nil, fmt.Errorf("the downloaded binary does not report %s (%v); nothing changed", p.Version, err)
	}
	// The new binary must accept every config it will run.
	configs := []string{env.Cfg.ProxyConfig, env.Cfg.WebConfig}
	instances, _ := filepath.Glob(filepath.Join(env.Cfg.SiteConfigDir, "*.Caddyfile"))
	for _, c := range append(configs, instances...) {
		if _, err := env.Exec.Run(ctx, "frankenphp-new", "validate", "--config", c, "--adapter", "caddyfile"); err != nil {
			os.Remove(newPath)
			return nil, fmt.Errorf("%s does not accept %s: %w; nothing changed", p.Version, c, err)
		}
	}

	// Swap (atomic renames, old binary kept), restart, verify, roll back on failure.
	_ = os.Remove(prevPath)
	if err := os.Link(bin, prevPath); err != nil {
		return nil, fmt.Errorf("keeping the old binary failed: %w", err)
	}
	if err := os.Rename(newPath, bin); err != nil {
		return nil, err
	}
	units := frankenUnits(env)
	restartAll := func() []string {
		var failed []string
		for _, u := range units {
			if _, err := env.Exec.Run(ctx, "systemctl", "restart", u); err != nil {
				failed = append(failed, u)
			}
		}
		for _, u := range []string{env.Cfg.WebUnit, env.Cfg.ProxyUnit} {
			if res, err := env.Exec.Run(ctx, "systemctl", "is-active", u); err != nil || strings.TrimSpace(res.Stdout) != "active" {
				failed = append(failed, u)
			}
		}
		return failed
	}
	if failed := restartAll(); len(failed) > 0 {
		_ = os.Rename(prevPath, bin)
		restartAll()
		return nil, fmt.Errorf("after the update %v did not run; the previous binary was restored", failed)
	}
	return map[string]any{"version": p.Version, "changed": true, "previous": before, "restarted": units, "sha256": asset.SHA256}, nil
}

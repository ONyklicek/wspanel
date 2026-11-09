package ops

import (
	"context"
	"crypto/rand"
	"encoding/base64"
	"math/big"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"

	"wspanel/agent/internal/protocol"
)

// What the panel configures in a Laravel or Symfony app by itself, so the
// pieces it runs (Valkey, Reverb, Horizon, Pulse) work without hand editing
// and always agree with the server.

// What a site's Valkey may be used for (valkey.set "use").
var valkeyUses = []string{"cache", "session", "queue"}

// valkeyUseEnv: the env keys for each use, per framework; off = the
// framework's own default again.
func valkeyUseEnv(framework string, uses []string, port int, password string) ([]string, map[string]string) {
	keys, values := []string{}, map[string]string{}
	on := func(u string) bool { return slices.Contains(uses, u) }
	switch framework {
	case "laravel":
		set := func(k, redis, def string, use string) {
			keys = append(keys, k)
			values[k] = def
			if on(use) {
				values[k] = redis
			}
		}
		set("CACHE_STORE", "redis", "database", "cache")
		set("SESSION_DRIVER", "redis", "database", "session")
		set("QUEUE_CONNECTION", "redis", "database", "queue")
	case "symfony":
		keys = append(keys, "MESSENGER_TRANSPORT_DSN")
		values["MESSENGER_TRANSPORT_DSN"] = "doctrine://default?auto_setup=0"
		if on("queue") {
			values["MESSENGER_TRANSPORT_DSN"] = "redis://:" + password + "@127.0.0.1:" + strconv.Itoa(port) + "/messages"
		}
	}
	return keys, values
}

// Workers that keep their data in Valkey.
var valkeyWorkers = []string{"horizon", "pulsework"}

// workerAppEnv prepares the app's env for a worker that was just installed.
func workerAppEnv(ctx context.Context, env *Env, l siteLayout, st siteState, worker string, publicPort int) error {
	if l.Framework != "laravel" {
		return nil
	}
	path := envFilePath(l)
	content, err := readSiteFile(ctx, env, l, path)
	if err != nil {
		return err
	}
	var keys []string
	values := map[string]string{}
	set := func(k, v string) { keys = append(keys, k); values[k] = v }
	switch worker {
	case "reverb":
		current := parseDotenv(content)
		keep := func(k string, gen func() string) string {
			if v := current[k]; v != "" {
				return v
			}
			return gen()
		}
		id := keep("REVERB_APP_ID", func() string { return randomDigits(6) })
		key := keep("REVERB_APP_KEY", func() string { return randomToken(20) })
		secret := keep("REVERB_APP_SECRET", func() string { return randomToken(32) })
		port := strconv.Itoa(st.ReverbPort)
		set("BROADCAST_CONNECTION", "reverb")
		set("REVERB_APP_ID", id)
		set("REVERB_APP_KEY", key)
		set("REVERB_APP_SECRET", secret)
		// The app publishes straight to Reverb on the server, never through the edge.
		set("REVERB_HOST", "127.0.0.1")
		set("REVERB_PORT", port)
		set("REVERB_SCHEME", "http")
		set("REVERB_SERVER_HOST", "127.0.0.1")
		set("REVERB_SERVER_PORT", port)
		// Browsers connect through the edge (TLS) at the site's own address.
		set("VITE_REVERB_APP_KEY", key)
		set("VITE_REVERB_HOST", l.Domain)
		set("VITE_REVERB_PORT", strconv.Itoa(publicPort))
		set("VITE_REVERB_SCHEME", "https")
	case "horizon":
		set("QUEUE_CONNECTION", "redis")
	case "pulsework":
		set("PULSE_INGEST_DRIVER", "redis")
	default:
		return nil
	}
	return writeSiteFile(ctx, env, l, path, mergeEnvSection(content, keys, values, "Set up by WSPanel for "+worker))
}

func checkValkeyWorker(st siteState, worker string) error {
	if slices.Contains(valkeyWorkers, worker) && st.Valkey == nil {
		return protocol.Conflict(worker + " needs the site's Valkey (valkey.set first)")
	}
	return nil
}

func randomDigits(n int) string {
	out := make([]byte, n)
	for i := range out {
		d, _ := rand.Int(rand.Reader, big.NewInt(10))
		out[i] = byte('0' + d.Int64())
	}
	if out[0] == '0' {
		out[0] = '1'
	}
	return string(out)
}

func randomToken(n int) string {
	const alphabet = "abcdefghijklmnopqrstuvwxyz0123456789"
	out := make([]byte, n)
	for i := range out {
		d, _ := rand.Int(rand.Reader, big.NewInt(int64(len(alphabet))))
		out[i] = alphabet[d.Int64()]
	}
	return string(out)
}

// Keys the panel writes and keeps when they are in the site's env file:
// what the servers it runs need (database, mail, Valkey, Reverb).
var panelEnvKeys = []string{
	"DB_CONNECTION", "DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD", "DATABASE_URL",
	"MAIL_MAILER", "MAIL_HOST", "MAIL_PORT", "MAIL_USERNAME", "MAIL_PASSWORD", "MAIL_FROM_ADDRESS", "MAILER_DSN",
	"SMTP_HOST", "SMTP_PORT", "SMTP_USER", "SMTP_PASS",
	"REDIS_CLIENT", "REDIS_HOST", "REDIS_PORT", "REDIS_PASSWORD", "REDIS_URL", "REDIS_SOCKET",
	// Reverb: where the app publishes and where the server listens. The app
	// id/key/secret and VITE_REVERB_* are not here: a CI build bakes them into
	// the frontend, so the deployed values must win (Reverb reads the same file).
	"REVERB_HOST", "REVERB_PORT", "REVERB_SCHEME", "REVERB_SERVER_HOST", "REVERB_SERVER_PORT",
}

// panelManagedKeys: the keys of the site's env file the panel owns right now.
// Drivers count only where the panel chose them (Valkey uses, workers).
func panelManagedKeys(env *Env, l siteLayout, st siteState, current map[string]string) map[string]bool {
	out := map[string]bool{}
	if st.Deploy != nil && st.Deploy.EnvFromDeploy {
		// The deployed file is the configuration; only where Reverb listens
		// is the server's to say.
		for _, k := range []string{"REVERB_SERVER_HOST", "REVERB_SERVER_PORT"} {
			if _, ok := current[k]; ok {
				out[k] = true
			}
		}
		return out
	}
	for _, k := range panelEnvKeys {
		if _, ok := current[k]; ok {
			out[k] = true
		}
	}
	if st.Valkey != nil {
		keys, _ := valkeyUseEnv(l.Framework, st.Valkey.Use, 0, "")
		uses := map[string]string{"CACHE_STORE": "cache", "SESSION_DRIVER": "session", "QUEUE_CONNECTION": "queue", "MESSENGER_TRANSPORT_DSN": "queue"}
		for _, k := range keys {
			if slices.Contains(st.Valkey.Use, uses[k]) {
				out[k] = true
			}
		}
	}
	// What the operator set in Prostředí: the panel's to keep, a deploy's
	// env file does not replace it.
	for _, k := range st.EnvVars {
		if _, ok := current[k]; ok {
			out[k] = true
		}
	}
	has := func(w string) bool { return hasWorker(env, l.User, w) }
	if has("reverb") {
		out["BROADCAST_CONNECTION"] = true
	}
	if has("horizon") {
		out["QUEUE_CONNECTION"] = true
	}
	if has("pulsework") {
		out["PULSE_INGEST_DRIVER"] = true
	}
	return out
}

var envKeyRe = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]{0,127}$`)

// mergeReleaseEnv takes the env file a release brought (a CI build, the
// repository) into the site's shared one before it is linked: its values
// win, except where the panel owns the key (the app must keep matching the
// servers the panel runs) and except empty values (an empty APP_KEY in a
// template never wipes the real one). Read as the site user.
func (d *deployment) mergeReleaseEnv(ctx context.Context) error {
	l := d.l
	f := sharedEnvFile(l.Framework)
	if f == "" {
		return nil
	}
	rel := filepath.Join(d.dir(), f)
	if fi, err := os.Lstat(rel); err != nil || !fi.Mode().IsRegular() {
		return nil
	}
	incoming, err := readSiteFile(ctx, d.env, l, rel)
	if err != nil {
		return err
	}
	sharedPath := filepath.Join(l.Home, "shared", f)
	current, err := readSiteFile(ctx, d.env, l, sharedPath)
	if err != nil {
		return err
	}
	st, _, err := loadSite(d.env, l.Domain)
	if err != nil {
		return err
	}
	keys, values, kept := envFromRelease(parseDotenv(incoming), panelManagedKeys(d.env, l, st, parseDotenv(current)))
	d.logf("\n==> %s from the release: %d values taken into shared/%s", f, len(keys), f)
	if len(kept) > 0 {
		d.logf("; kept as the panel set them: %s", strings.Join(kept, ", "))
	}
	d.logf("\n")
	if len(keys) == 0 {
		return nil
	}
	return writeSiteFile(ctx, d.env, l, sharedPath, mergeEnvSection(current, keys, values, "From the deployed release (written by WSPanel)"))
}

// ensureAppSecrets gives a Laravel / Symfony site the secret its framework
// will not start without (APP_KEY / APP_SECRET) when its shared env file has
// none: a first deploy of a CI build would otherwise answer 500. A value the
// release brought (merged above) or the operator set is never replaced.
func (d *deployment) ensureAppSecrets(ctx context.Context) error {
	l := d.l
	key, value := "", ""
	switch l.Framework {
	case "laravel":
		raw := make([]byte, 32)
		_, _ = rand.Read(raw)
		key, value = "APP_KEY", "base64:"+base64.StdEncoding.EncodeToString(raw)
	case "symfony":
		key, value = "APP_SECRET", randomHex(16)
	default:
		return nil
	}
	path := filepath.Join(l.Home, "shared", sharedEnvFile(l.Framework))
	current, err := readSiteFile(ctx, d.env, l, path)
	if err != nil {
		return err
	}
	if parseDotenv(current)[key] != "" {
		return nil
	}
	d.logf("\n==> %s\nnone in shared/%s: a new one was generated\n", key, sharedEnvFile(l.Framework))
	return writeSiteFile(ctx, d.env, l, path, mergeEnvSection(current, []string{key}, map[string]string{key: value}, "Application secret (generated by WSPanel)"))
}

// envFromRelease picks what a release's env file may set: valid keys with a
// value that the panel does not own (kept lists the owned ones it brought).
func envFromRelease(in map[string]string, managed map[string]bool) (keys []string, values map[string]string, kept []string) {
	values = map[string]string{}
	for k, v := range in {
		switch {
		case !envKeyRe.MatchString(k) || v == "":
		case managed[k]:
			kept = append(kept, k)
		default:
			keys = append(keys, k)
			values[k] = v
		}
	}
	slices.Sort(keys)
	slices.Sort(kept)
	return keys, values, kept
}

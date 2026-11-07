package ops

import (
	"context"
	"crypto/rand"
	"math/big"
	"slices"
	"strconv"

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

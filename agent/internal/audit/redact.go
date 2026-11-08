package audit

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
)

// sensitive lists params that must never reach the audit log in clear: the
// whole env file (passwords, keys) and anything named like a secret.
var sensitive = map[string]map[string]bool{
	"env.set":               {"content": true},
	"site.tls":              {"key": true, "cert": true},               // private key; the chain is just bulky
	"site.files":            {"content": true},                         // edited files (.env, configs) may hold secrets
	"offsite.set":           {"secret_key": true, "signing_key": true}, // storage credentials, backup signing key
	"mail.mailbox.password": {"current_password": true},                // the mailbox's own, proving the change
	"*":                     {"password": true, "secret": true, "token": true, "key": true, "private_key": true},
}

// Redact replaces sensitive values with their length and a short hash, so
// the log still shows that (and roughly what) changed without the secret.
func Redact(op string, params json.RawMessage) json.RawMessage {
	if len(params) == 0 {
		return params
	}
	var m map[string]any
	if json.Unmarshal(params, &m) != nil {
		return json.RawMessage(`"<unparsable params>"`)
	}
	changed := false
	for k, v := range m {
		if sensitive[op][k] || sensitive["*"][k] {
			s := fmt.Sprint(v)
			sum := sha256.Sum256([]byte(s))
			m[k] = fmt.Sprintf("<redacted %d bytes sha256:%s>", len(s), hex.EncodeToString(sum[:])[:12])
			changed = true
		}
	}
	if !changed {
		return params
	}
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false) // keep "<redacted …>" readable in the log
	_ = enc.Encode(m)
	return bytes.TrimSpace(buf.Bytes())
}

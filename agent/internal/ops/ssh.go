package ops

import (
	"context"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"wspanel/agent/internal/fsx"
	"wspanel/agent/internal/protocol"
	"wspanel/agent/internal/validate"
)

// SSH access levels for a site user.
var SSHAccessLevels = []string{"off", "sftp", "shell"}

var sshKeyTypes = map[string]bool{
	"ssh-ed25519":                        true,
	"ecdsa-sha2-nistp256":                true,
	"ecdsa-sha2-nistp384":                true,
	"ecdsa-sha2-nistp521":                true,
	"ssh-rsa":                            true,
	"sk-ssh-ed25519@openssh.com":         true,
	"sk-ecdsa-sha2-nistp256@openssh.com": true,
}

var keyCommentRe = regexp.MustCompile(`^[\x21-\x7e][\x20-\x7e]{0,99}$`)

// parsePublicKey accepts "type base64 [comment]" only. Options (command=,
// from=, ...) are not accepted: the agent writes its own. The blob must decode
// and name the same key type, so the line cannot smuggle anything else.
func parsePublicKey(line string) (string, error) {
	line = strings.TrimSpace(line)
	parts := strings.SplitN(line, " ", 3)
	if len(parts) < 2 || !sshKeyTypes[parts[0]] {
		return "", protocol.Invalid("keys: each key must start with a supported type (ssh-ed25519, ecdsa-sha2-*, ssh-rsa, sk-*)")
	}
	blob, err := base64.StdEncoding.DecodeString(parts[1])
	if err != nil || len(blob) < 4 {
		return "", protocol.Invalid("keys: invalid base64 key data")
	}
	n := binary.BigEndian.Uint32(blob[:4])
	if int(n) > len(blob)-4 || string(blob[4:4+n]) != parts[0] {
		return "", protocol.Invalid("keys: key data does not match its type")
	}
	if parts[0] == "ssh-rsa" && len(blob) < 270 { // < 2048-bit modulus
		return "", protocol.Invalid("keys: RSA keys must be at least 2048 bits (prefer ssh-ed25519)")
	}
	out := parts[0] + " " + parts[1]
	if len(parts) == 3 {
		c := strings.TrimSpace(parts[2])
		if c != "" {
			if !keyCommentRe.MatchString(c) {
				return "", protocol.Invalid("keys: comment may contain printable ASCII only (max 100 chars)")
			}
			out += " " + c
		}
	}
	return out, nil
}

type sshSetParams struct {
	Domain string   `json:"domain"`
	Access string   `json:"access"`
	Keys   []string `json:"keys"`
	// Password login: "keep" (default), "set" (Password) or "remove".
	PasswordMode string `json:"password_mode,omitempty"`
	Password     string `json:"password,omitempty"`
}

// Passwords go to chpasswd as "user:password" lines: no colon, no control
// characters. Length makes guessing impractical; fail2ban limits attempts.
var sshPasswordRe = regexp.MustCompile(`^[\x20-\x39\x3b-\x7e]{12,128}$`)

// sshSet sets SSH/SFTP access for a site user: login shell, group (sshd
// applies per-group restrictions) and the root-owned authorized_keys file.
// Every key gets `restrict` (no forwarding of any kind, no agent, no X11,
// no ~/.ssh/rc); shell access adds back only a pty.
func sshSet(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p sshSetParams
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := validate.OneOf("access", p.Access, SSHAccessLevels); err != nil {
		return nil, err
	}
	if len(p.Keys) > 20 {
		return nil, protocol.Invalid("keys: at most 20 keys")
	}
	if p.PasswordMode == "" {
		p.PasswordMode = "keep"
	}
	if err := validate.OneOf("password_mode", p.PasswordMode, []string{"keep", "set", "remove"}); err != nil {
		return nil, err
	}
	if p.PasswordMode == "set" && !sshPasswordRe.MatchString(p.Password) {
		return nil, protocol.Invalid("password: 12–128 printable ASCII characters, no colon")
	}
	if p.PasswordMode != "set" && p.Password != "" {
		return nil, protocol.Invalid("password: only with password_mode set")
	}
	keys := make([]string, 0, len(p.Keys))
	for _, k := range p.Keys {
		parsed, err := parsePublicKey(k)
		if err != nil {
			return nil, err
		}
		keys = append(keys, parsed)
	}
	st, l, err := loadSite(env, p.Domain)
	if err != nil {
		return nil, err
	}

	hasPassword := (st.SSHPassword && p.PasswordMode == "keep") || p.PasswordMode == "set"
	if p.Access == "off" {
		hasPassword = false
	}
	if p.Access != "off" && len(keys) == 0 && !hasPassword {
		return nil, protocol.Invalid("keys: at least one public key or a password is required")
	}

	keyFile := filepath.Join(env.Cfg.AuthorizedKeysDir, l.User)
	shell, group := "/usr/sbin/nologin", ""
	switch p.Access {
	case "shell":
		shell, group = "/bin/bash", env.Cfg.SSHGroup
	case "sftp":
		group = env.Cfg.SFTPGroup
	}

	if p.Access == "off" || len(keys) == 0 {
		if err := os.Remove(keyFile); err != nil && !os.IsNotExist(err) {
			return nil, err
		}
	} else {
		opts := "restrict"
		if p.Access == "shell" {
			opts = "restrict,pty"
		}
		var b strings.Builder
		b.WriteString("# Managed by WSPanel agent. Edit SSH keys in the panel.\n")
		for _, k := range keys {
			b.WriteString(opts + " " + k + "\n")
		}
		if err := env.FS.MkdirAll(env.Cfg.AuthorizedKeysDir, 0o755, fsx.Root); err != nil {
			return nil, err
		}
		if err := env.FS.WriteFile(keyFile, []byte(b.String()), 0o644, fsx.Root); err != nil {
			return nil, err
		}
	}
	// Supplementary groups are exactly the SSH group (or none); site users have no others.
	if _, err := env.Exec.Run(ctx, "usermod", "--groups", group, "--shell", shell, l.User); err != nil {
		return nil, err
	}
	// Password: set through chpasswd's stdin (never argv), or locked.
	if hasPassword && p.PasswordMode == "set" {
		if _, err := env.Exec.RunIO(ctx, strings.NewReader(l.User+":"+p.Password+"\n"), nil, "chpasswd"); err != nil {
			return nil, err
		}
	} else if !hasPassword {
		if _, err := env.Exec.Run(ctx, "usermod", "--lock", l.User); err != nil {
			return nil, err
		}
	}
	// Any change ends existing sessions and everything started from them, so
	// revoked access cannot linger in a nohup/screen process.
	if err := killSessionProcesses(ctx, env, l.User); err != nil {
		return nil, fmt.Errorf("access set, but ending existing sessions failed: %w", err)
	}
	st.SSHAccess = p.Access
	st.SSHPassword = hasPassword
	if err := saveSiteState(env, st); err != nil {
		return nil, err
	}
	if err := writeToolLinks(env, l); err != nil {
		return nil, fmt.Errorf("access set, but updating tool links failed: %w", err)
	}
	return map[string]any{"domain": p.Domain, "user": l.User, "access": p.Access, "keys": len(keys), "password": hasPassword, "home": l.Home}, nil
}

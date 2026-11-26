package ops

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"path"
	"sort"
	"strconv"
	"strings"
	"time"

	"wspanel/agent/internal/protocol"
)

// smbBrowse (the panel's forms for an SMB disk or off-site storage): what
// is on a server, for someone who does not know its layout. Without a share
// it lists the shares the account sees; with one, the folders in path and
// whether the account may write there (a folder made and removed again).
// The password goes to smbclient in a root-only file, as for off-site.
func smbBrowse(ctx context.Context, env *Env, raw json.RawMessage) (any, error) {
	var p struct {
		Host     string `json:"host"`
		Port     int    `json:"port"`
		User     string `json:"user"`
		Password string `json:"password"`
		Domain   string `json:"domain"`
		Encrypt  bool   `json:"encrypt"`
		Share    string `json:"share"`
		Path     string `json:"path"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	switch {
	case !mountHostRe.MatchString(p.Host):
		return nil, protocol.Invalid("host: a name or an IP address")
	case p.Port < 0 || p.Port > 65535:
		return nil, protocol.Invalid("port: 1 to 65535")
	case !mountLoginRe.MatchString(p.User):
		return nil, protocol.Invalid("user: required")
	case !mountSecretRe.MatchString(p.Password):
		return nil, protocol.Invalid("password: up to 256 characters")
	case p.Domain != "" && !mountLoginRe.MatchString(p.Domain):
		return nil, protocol.Invalid("domain: unexpected characters")
	case p.Share != "" && (!mountShareRe.MatchString(p.Share) || strings.HasSuffix(p.Share, "$")):
		return nil, protocol.Invalid("share: unexpected characters")
	}
	dir := strings.Trim(path.Clean("/"+p.Path), "/")
	if dir != "" && (!smbSafeRe.MatchString(dir) || strings.Contains(dir, "..")) {
		return nil, protocol.Invalid("path: letters, digits, spaces and . _ - / only")
	}
	if err := ensureSMBClient(ctx, env); err != nil {
		return nil, err
	}
	st, err := openSMB(env, offsiteConfig{Host: strings.Trim(p.Host, "[]"), Port: p.Port, Username: p.User, Password: p.Password,
		Domain: p.Domain, Share: p.Share, SMBEncrypt: p.Encrypt})
	if err != nil {
		return nil, err
	}
	s := st.(*smbStore)
	defer s.Close()
	bctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()

	if p.Share == "" {
		shares, err := s.shares(bctx)
		if err != nil {
			return nil, err
		}
		return map[string]any{"shares": shares}, nil
	}
	dirs, err := s.dirs(bctx, dir)
	if err != nil {
		return nil, err
	}
	return map[string]any{"share": p.Share, "path": dir, "dirs": dirs, "writable": s.writable(bctx, dir)}, nil
}

type smbShare struct {
	Name    string `json:"name"`
	Comment string `json:"comment,omitempty"`
}

// shares: the disk shares the account sees (smbclient -L, grepable),
// without the administrative ones (C$, ADMIN$, IPC$).
func (s *smbStore) shares(ctx context.Context) ([]smbShare, error) {
	port := s.c.Port
	if port == 0 {
		port = 445
	}
	args := []string{"-L", "//" + s.c.Host, "-A", s.auth, "-p", strconv.Itoa(port), "-g",
		"--option=client min protocol=SMB2_02", "-m", "SMB3"}
	if s.c.SMBEncrypt {
		args = append(args, "--client-protection=encrypt")
	}
	res, err := s.env.Exec.RunIO(ctx, nil, nil, "smbclient", args...)
	out := res.Stdout + res.Stderr
	if m := ntStatusRe.FindString(out); m != "" && m != "NT_STATUS_OK" {
		return nil, errors.New(m)
	}
	if err != nil {
		return nil, fmt.Errorf("smbclient failed: %s", lastLine(out))
	}
	list := []smbShare{}
	for _, line := range strings.Split(out, "\n") {
		f := strings.SplitN(strings.TrimRight(line, "\r"), "|", 3)
		if len(f) < 2 || f[0] != "Disk" || strings.HasSuffix(f[1], "$") {
			continue
		}
		sh := smbShare{Name: f[1]}
		if len(f) == 3 {
			sh.Comment = f[2]
		}
		list = append(list, sh)
	}
	sort.Slice(list, func(i, j int) bool { return strings.ToLower(list[i].Name) < strings.ToLower(list[j].Name) })
	return list, nil
}

// dirs: the folders in dir on the share.
func (s *smbStore) dirs(ctx context.Context, dir string) ([]string, error) {
	pattern := "*"
	if dir != "" {
		p, err := smbPath(dir)
		if err != nil {
			return nil, err
		}
		pattern = p + `\*`
	}
	list := []string{}
	out, err := s.run(ctx, `ls "`+pattern+`"`)
	if err != nil {
		if err.Error() == "NT_STATUS_NO_SUCH_FILE" {
			return list, nil // an empty folder
		}
		return nil, err
	}
	for _, line := range strings.Split(out, "\n") {
		m := smbLsRe.FindStringSubmatch(strings.TrimRight(line, "\r"))
		if m == nil || !strings.Contains(m[2], "D") || m[1] == "." || m[1] == ".." || strings.Contains(m[2], "H") {
			continue
		}
		list = append(list, m[1])
	}
	sort.Slice(list, func(i, j int) bool { return strings.ToLower(list[i]) < strings.ToLower(list[j]) })
	return list, nil
}

// writable: whether the account may create (and remove) in dir.
func (s *smbStore) writable(ctx context.Context, dir string) bool {
	probe := path.Join(dir, fmt.Sprintf(".wspanel-write-test-%d", time.Now().UnixNano()))
	p, err := smbPath(probe)
	if err != nil {
		return false
	}
	if _, err := s.run(ctx, `mkdir "`+p+`"`); err != nil {
		return false
	}
	_, _ = s.run(ctx, `rmdir "`+p+`"`)
	return true
}

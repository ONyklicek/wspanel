// Command wspanel is the command line for a WSPanel server, over its REST API
// (a personal token from the panel: Účet → API a CLI).
//
//	wspanel login https://panel.example.com wspa_…   saves the URL and token
//	wspanel sites                                     your sites
//	wspanel site example.com                          one site
//	wspanel deploy example.com [ref] [--wait]         deploy (a tag/branch/commit)
//	wspanel deploys example.com                       deploy history
//	wspanel restart example.com                       restart the app and workers
//	wspanel databases | mail | dns | whoami
//
// WSPANEL_URL and WSPANEL_TOKEN override the saved login; --json prints the
// API's answer as it is.
package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"text/tabwriter"
	"time"
)

type config struct {
	URL   string `json:"url"`
	Token string `json:"token"`
}

type client struct {
	cfg  config
	http *http.Client
}

var jsonOut bool

func main() {
	args := []string{}
	wait := false
	for _, a := range os.Args[1:] {
		switch a {
		case "--json":
			jsonOut = true
		case "--wait":
			wait = true
		default:
			args = append(args, a)
		}
	}
	if err := run(args, wait); err != nil {
		fmt.Fprintln(os.Stderr, "wspanel:", err)
		os.Exit(1)
	}
}

func configPath() string {
	dir, err := os.UserConfigDir()
	if err != nil {
		dir = filepath.Join(os.Getenv("HOME"), ".config")
	}
	return filepath.Join(dir, "wspanel", "config.json")
}

func loadConfig() config {
	var c config
	if b, err := os.ReadFile(configPath()); err == nil {
		_ = json.Unmarshal(b, &c)
	}
	if v := os.Getenv("WSPANEL_URL"); v != "" {
		c.URL = v
	}
	if v := os.Getenv("WSPANEL_TOKEN"); v != "" {
		c.Token = v
	}
	c.URL = strings.TrimRight(c.URL, "/")
	return c
}

func run(args []string, wait bool) error {
	if len(args) == 0 || args[0] == "help" || args[0] == "--help" {
		fmt.Print(usage)
		return nil
	}
	if args[0] == "login" {
		if len(args) != 3 {
			return errors.New("usage: wspanel login <panel url> <token>")
		}
		u, err := url.Parse(args[1])
		if err != nil || u.Scheme != "https" || u.Host == "" {
			return errors.New("the panel URL must be https://…")
		}
		c := &client{cfg: config{URL: strings.TrimRight(args[1], "/"), Token: args[2]}, http: &http.Client{Timeout: 30 * time.Second}}
		var me map[string]any
		if err := c.get("/me", &me); err != nil {
			return err
		}
		b, _ := json.MarshalIndent(c.cfg, "", "  ")
		if err := os.MkdirAll(filepath.Dir(configPath()), 0o700); err != nil {
			return err
		}
		if err := os.WriteFile(configPath(), b, 0o600); err != nil {
			return err
		}
		fmt.Printf("Signed in as %v (%v). Saved to %s\n", me["email"], me["role"], configPath())
		return nil
	}
	cfg := loadConfig()
	if cfg.URL == "" || cfg.Token == "" {
		return errors.New("not signed in: wspanel login <panel url> <token> (or WSPANEL_URL and WSPANEL_TOKEN)")
	}
	c := &client{cfg: cfg, http: &http.Client{Timeout: 60 * time.Second}}
	need := func(n int) error {
		if len(args) < n {
			return fmt.Errorf("missing argument, see wspanel help")
		}
		return nil
	}
	switch args[0] {
	case "whoami":
		return c.show("/me", nil)
	case "sites":
		return c.show("/sites", []string{"domain", "mode", "framework", "status", "healthy", "url"})
	case "site":
		if err := need(2); err != nil {
			return err
		}
		return c.show("/sites/"+url.PathEscape(args[1]), nil)
	case "deploys":
		if err := need(2); err != nil {
			return err
		}
		return c.show("/sites/"+url.PathEscape(args[1])+"/deploys", []string{"id", "status", "ref", "started", "finished", "error"})
	case "deploy":
		if err := need(2); err != nil {
			return err
		}
		body := map[string]string{}
		if len(args) > 2 {
			body["ref"] = args[2]
		}
		var out map[string]any
		if err := c.post("/sites/"+url.PathEscape(args[1])+"/deploy", body, &out); err != nil {
			return err
		}
		release, _ := out["release"].(string)
		fmt.Println("deploy started:", release)
		if wait && release != "" {
			return c.waitDeploy(args[1], release)
		}
		return nil
	case "restart":
		if err := need(2); err != nil {
			return err
		}
		var out map[string]any
		if err := c.post("/sites/"+url.PathEscape(args[1])+"/restart", nil, &out); err != nil {
			return err
		}
		fmt.Println("restarted")
		return nil
	case "databases":
		return c.show("/databases", []string{"name", "engine", "site"})
	case "mail":
		return c.show("/mail/domains", []string{"domain", "mailboxes", "webmail"})
	case "dns":
		return c.show("/dns/zones", []string{"domain", "provider"})
	}
	return fmt.Errorf("unknown command %q, see wspanel help", args[0])
}

func (c *client) waitDeploy(domain, release string) error {
	for i := 0; i < 360; i++ {
		var out struct {
			Data []map[string]any `json:"data"`
		}
		if err := c.get("/sites/"+url.PathEscape(domain)+"/deploys", &out); err != nil {
			return err
		}
		for _, d := range out.Data {
			if d["id"] != release {
				continue
			}
			switch d["status"] {
			case "success":
				fmt.Println("deployed", release)
				return nil
			case "failed", "rolled_back":
				return fmt.Errorf("deploy %s %v: %v", release, d["status"], d["error"])
			}
		}
		time.Sleep(5 * time.Second)
	}
	return errors.New("still running after 30 minutes")
}

func (c *client) do(method, path string, body any, out any) error {
	var rd io.Reader
	if body != nil {
		b, _ := json.Marshal(body)
		rd = bytes.NewReader(b)
	}
	req, err := http.NewRequest(method, c.cfg.URL+"/api/v1"+path, rd)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+c.cfg.Token)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "wspanel-cli")
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	resp, err := c.http.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 16<<20))
	if resp.StatusCode >= 300 {
		var e struct {
			Error   string `json:"error"`
			Message string `json:"message"`
		}
		_ = json.Unmarshal(b, &e)
		msg := e.Error
		if msg == "" {
			msg = e.Message
		}
		if msg == "" {
			msg = http.StatusText(resp.StatusCode)
		}
		return fmt.Errorf("%s (HTTP %d)", msg, resp.StatusCode)
	}
	if raw, ok := out.(*json.RawMessage); ok {
		*raw = b
		return nil
	}
	return json.Unmarshal(b, out)
}

func (c *client) get(path string, out any) error {
	return c.do(http.MethodGet, path, nil, out)
}

func (c *client) post(path string, body, out any) error {
	return c.do(http.MethodPost, path, body, out)
}

// show prints a list as a table (cols) or an object as key: value lines.
func (c *client) show(path string, cols []string) error {
	var raw json.RawMessage
	if err := c.get(path, &raw); err != nil {
		return err
	}
	if jsonOut {
		fmt.Println(string(raw))
		return nil
	}
	var out any
	if err := json.Unmarshal(raw, &out); err != nil {
		return err
	}
	if m, ok := out.(map[string]any); ok {
		if data, ok := m["data"]; ok {
			out = data
		}
	}
	w := tabwriter.NewWriter(os.Stdout, 0, 4, 2, ' ', 0)
	defer w.Flush()
	switch v := out.(type) {
	case []any:
		if cols == nil {
			b, _ := json.MarshalIndent(v, "", "  ")
			fmt.Fprintln(w, string(b))
			return nil
		}
		fmt.Fprintln(w, strings.ToUpper(strings.Join(cols, "\t")))
		for _, row := range v {
			r, _ := row.(map[string]any)
			vals := make([]string, len(cols))
			for i, col := range cols {
				vals[i] = cell(r[col])
			}
			fmt.Fprintln(w, strings.Join(vals, "\t"))
		}
	case map[string]any:
		for k, val := range v {
			fmt.Fprintf(w, "%s:\t%s\n", k, cell(val))
		}
	}
	return nil
}

func cell(v any) string {
	switch t := v.(type) {
	case nil:
		return "-"
	case string:
		return t
	case bool:
		if t {
			return "yes"
		}
		return "no"
	case float64:
		return fmt.Sprintf("%g", t)
	}
	b, _ := json.Marshal(v)
	return string(b)
}

const usage = `wspanel: the command line for a WSPanel server

  wspanel login <panel url> <token>     save the panel URL and a token (Účet → API a CLI)
  wspanel whoami
  wspanel sites                         your sites
  wspanel site <domain>
  wspanel deploy <domain> [ref] [--wait]
  wspanel deploys <domain>
  wspanel restart <domain>              the app and its workers
  wspanel databases | mail | dns

  --json      print the API's answer as it is
  WSPANEL_URL, WSPANEL_TOKEN override the saved login.
`

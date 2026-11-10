// Package audit appends one JSON line per operation to the audit log.
package audit

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"sync"
	"time"
)

type Entry struct {
	Time       time.Time       `json:"ts"`
	Op         string          `json:"op"`
	Params     json.RawMessage `json:"params,omitempty"`
	PeerUID    int64           `json:"peer_uid"`
	OK         bool            `json:"ok"`
	Error      string          `json:"error,omitempty"`
	DurationMS int64           `json:"duration_ms"`
	DryRun     bool            `json:"dry_run,omitempty"`
}

// Log is the audit log file. It follows logrotate (/etc/logrotate.d/
// wspanel-agent): when the file at the path is no longer the one held open
// (renamed away, or removed), the next entry goes to a new file there.
type Log struct {
	mu   sync.Mutex
	path string
	f    *os.File
}

func Open(path string) (*Log, error) {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return nil, err
	}
	f, err := openFile(path)
	if err != nil {
		return nil, err
	}
	return &Log{path: path, f: f}, nil
}

func openFile(path string) (*os.File, error) {
	return os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
}

func (l *Log) Write(e Entry) error {
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false) // keep "<redacted …>" readable
	if err := enc.Encode(e); err != nil {
		return err
	}
	l.mu.Lock()
	defer l.mu.Unlock()
	l.followRotation()
	_, err := l.f.Write(buf.Bytes())
	return err
}

// followRotation reopens the path when it names another file than the one
// held open. On any error the old file is kept: an entry in a rotated file
// beats an entry lost.
func (l *Log) followRotation() {
	held, err := l.f.Stat()
	if err != nil {
		return
	}
	if cur, err := os.Stat(l.path); err == nil && os.SameFile(held, cur) {
		return
	}
	f, err := openFile(l.path)
	if err != nil {
		return
	}
	_ = l.f.Close()
	l.f = f
}

func (l *Log) Close() error { return l.f.Close() }

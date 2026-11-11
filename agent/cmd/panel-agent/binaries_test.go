package main

import (
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"

	"wspanel/agent/internal/config"
)

// Every binary the operations name must be on the executor's allowlist: the
// tests run the operations on a dry-run executor that allows anything, so a
// name missing here only shows on a real server ("not an allowed binary").
func TestEveryBinaryTheOperationsRunIsAllowed(t *testing.T) {
	calls := []*regexp.Regexp{
		regexp.MustCompile(`Exec\.Run\([^,()]+, "([^"]+)"[,)]`),
		regexp.MustCompile(`Exec\.RunLog\([^,()]+, [^,()]+, "([^"]+)"[,)]`),
		regexp.MustCompile(`Exec\.RunIO\([^,()]+, [^,()]+, [^,()]+, "([^"]+)"[,)]`),
	}
	files, err := filepath.Glob("../../internal/ops/*.go")
	if err != nil || len(files) == 0 {
		t.Fatal("no operations found")
	}
	allowed := binaries(config.Defaults())
	seen := 0
	for _, f := range files {
		if strings.HasSuffix(f, "_test.go") {
			continue
		}
		src, err := os.ReadFile(f)
		if err != nil {
			t.Fatal(err)
		}
		for _, re := range calls {
			for _, m := range re.FindAllStringSubmatch(string(src), -1) {
				seen++
				if _, ok := allowed[m[1]]; !ok {
					t.Errorf("%s runs %q, which the executor does not allow", filepath.Base(f), m[1])
				}
			}
		}
	}
	if seen < 50 {
		t.Fatalf("found only %d calls: the pattern no longer matches the code", seen)
	}
}

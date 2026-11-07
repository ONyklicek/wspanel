# WSPanel — development tasks. Production runs natively (systemd); Docker is
# only used for the dev "server" container.

VERSION ?= $(shell date -u +%Y%m%d%H%M%S)
ARCH    ?= amd64
DB      ?= mariadb,postgres
PHP     ?= 8.3,8.4
LDFLAGS := -s -w -X wspanel/agent/internal/ops.Version=$(VERSION)
DEV     := docker compose -f dev/compose.yaml

.PHONY: dev-demo test agent-test panel-test agent-build agent-dry dev-up dev-provision dev-e2e dev-shell dev-logs dev-down

test: agent-test panel-test

agent-test:
	cd agent && go vet ./... && go test ./...

panel-test:
	cd panel && php artisan test

## Static Linux binary for the server / dev container.
agent-build:
	mkdir -p build
	cd agent && CGO_ENABLED=0 GOOS=linux GOARCH=$(ARCH) go build -trimpath -ldflags '$(LDFLAGS)' -o ../build/panel-agent-linux-$(ARCH) ./cmd/panel-agent

## Agent on macOS in dry-run mode: records commands instead of running them,
## writes files under var/dry-root. Panel's .env points AGENT_SOCKET at var/agent.sock.
agent-dry:
	mkdir -p var/state var/dry-root/srv/sites var/dry-root/etc/panel-proxy/sites var/dry-root/etc/panel-web/sites var/dry-root/etc/systemd/system \
		var/dry-root/etc/php/8.3/fpm/pool.d var/dry-root/etc/php/8.4/fpm/pool.d \
		var/dry-root/etc/panel-sites var/dry-root/etc/tmpfiles.d
	for v in v22.12.0 v24.1.0; do mkdir -p var/dry-root/opt/fnm/node-versions/$$v/installation/bin && touch var/dry-root/opt/fnm/node-versions/$$v/installation/bin/node; done
	cd agent && go build -o ../var/panel-agent-dev ./cmd/panel-agent
	./var/panel-agent-dev -config dev/agent.dryrun.json

## Debian 13 container with systemd as PID 1 — stands in for the real server.
dev-up:
	$(DEV) up -d --build

dev-provision: agent-build
	$(DEV) exec devserver /opt/wspanel/provision/install.sh \
		--db=$(DB) --php=$(PHP) --panel-domain=panel.localhost \
		--agent-bin=/opt/wspanel/build/panel-agent-linux-$(ARCH) \
		--panel-source=/opt/wspanel/panel --local-certs \
		--public-https-port=8443 --public-ssh-port=2222 --mail=capture \
		--allow-unenforced-socket-bind # Docker Desktop: no cgroup BPF socket-bind

## Real demo apps (Laravel, Symfony Demo, WordPress over SFTP) in the dev container.
dev-demo:
	$(DEV) exec devserver /opt/wspanel/dev/demo.sh

## Full end-to-end test inside the dev container (after dev-provision).
dev-e2e:
	$(DEV) exec devserver /opt/wspanel/dev/e2e.sh

dev-shell:
	$(DEV) exec devserver bash

dev-logs:
	$(DEV) exec devserver journalctl -f -u panel-agent -u panel-proxy

dev-down:
	$(DEV) down

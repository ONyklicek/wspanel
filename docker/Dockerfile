# WSPanel as one Docker container: for hosts where it cannot run natively
# (Windows with Docker Desktop / WSL2). The container is a whole Debian 13
# server: systemd as PID 1, everything installed by the release's installer
# on the first start, exactly as on a native server. See docs/docker.md.
#
# Build context: the repository root, with the release bundles in dist/
# (wspanel-<version>-linux-<arch>.tar.gz; Dockerfile.dockerignore sends
# nothing else). make docker-image VERSION=… does it for this machine:
#
#   docker buildx build --platform linux/amd64,linux/arm64 \
#     --build-arg WSPANEL_VERSION=1.10.0 -f docker/Dockerfile .
FROM debian:trixie

ARG WSPANEL_VERSION
ARG TARGETARCH

ENV container=docker DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
 && apt-get install -y --no-install-recommends systemd systemd-sysv dbus ca-certificates curl sqv procps less nano whiptail \
 && apt-get clean && rm -rf /var/lib/apt/lists/* \
 # A server, not an image build: services may start and restart.
 && rm -f /usr/sbin/policy-rc.d \
 # Units that make no sense inside a container.
 && systemctl mask systemd-firstboot.service systemd-udevd.service systemd-modules-load.service \
      sys-kernel-debug.mount sys-kernel-tracing.mount getty.target console-getty.service

# The release this image installs on its first start (and nothing else:
# later upgrades go through the panel, inside the container).
COPY dist/wspanel-${WSPANEL_VERSION}-linux-${TARGETARCH}.tar.gz /tmp/wspanel.tar.gz
RUN [ -n "$WSPANEL_VERSION" ] || { echo "WSPANEL_VERSION is required" >&2; exit 1; } \
 && install -d -m 0700 /var/lib/wspanel-installer/$WSPANEL_VERSION \
 && tar -xzf /tmp/wspanel.tar.gz -C /var/lib/wspanel-installer/$WSPANEL_VERSION \
 && rm /tmp/wspanel.tar.gz \
 && ln -s /var/lib/wspanel-installer/$WSPANEL_VERSION/wspanel /opt/wspanel-image \
 && test -x /opt/wspanel-image/install

COPY --chmod=0755 docker/wspanel-docker-init /usr/local/sbin/wspanel-docker-init
COPY --chmod=0755 docker/wspanel-docker-setup /usr/local/sbin/wspanel-docker-setup
COPY docker/wspanel-docker-setup.service /etc/systemd/system/wspanel-docker-setup.service
RUN systemctl enable wspanel-docker-setup.service

LABEL org.opencontainers.image.title="WSPanel" \
      org.opencontainers.image.description="WSPanel hosting panel as a single systemd container (Docker Desktop / Windows)" \
      org.opencontainers.image.version="${WSPANEL_VERSION}" \
      org.opencontainers.image.licenses="see LICENSE"

STOPSIGNAL SIGRTMIN+3
VOLUME ["/sys/fs/cgroup"]
ENTRYPOINT ["/usr/local/sbin/wspanel-docker-init"]

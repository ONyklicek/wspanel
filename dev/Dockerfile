# Dev/test stand-in for a real Debian 13 server: systemd runs as PID 1 and
# everything else (agent, proxy, PHP-FPM, databases) is installed natively by
# provision/install.sh — exactly as in production. Not for production use.
FROM debian:trixie

ENV container=docker DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
 && apt-get install -y --no-install-recommends systemd systemd-sysv dbus ca-certificates curl procps less nano \
 && apt-get clean && rm -rf /var/lib/apt/lists/* \
 # Units that make no sense inside a container.
 && systemctl mask systemd-firstboot.service systemd-udevd.service systemd-modules-load.service \
      sys-kernel-debug.mount sys-kernel-tracing.mount getty.target console-getty.service

STOPSIGNAL SIGRTMIN+3
VOLUME ["/sys/fs/cgroup"]
CMD ["/sbin/init"]

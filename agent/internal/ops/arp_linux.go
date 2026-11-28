//go:build linux

package ops

import (
	"errors"
	"net"
	"syscall"
	"time"
)

// Raw ARP for the mirror's floating IP: a gratuitous announcement (the new
// holder) and a probe (RFC 5227: does anyone answer for the address?).

func htons(v uint16) uint16 { return v<<8 | v>>8 }

func arpOpen(ifname string) (int, *net.Interface, error) {
	ifi, err := net.InterfaceByName(ifname)
	if err != nil {
		return -1, nil, err
	}
	if len(ifi.HardwareAddr) != 6 {
		return -1, nil, errors.New(ifname + " is not an Ethernet interface")
	}
	fd, err := syscall.Socket(syscall.AF_PACKET, syscall.SOCK_RAW|syscall.SOCK_CLOEXEC, int(htons(ethPARP)))
	if err != nil {
		return -1, nil, err
	}
	if err := syscall.Bind(fd, &syscall.SockaddrLinklayer{Protocol: htons(ethPARP), Ifindex: ifi.Index}); err != nil {
		syscall.Close(fd)
		return -1, nil, err
	}
	return fd, ifi, nil
}

func arpSend(fd int, ifi *net.Interface, frame []byte) error {
	to := &syscall.SockaddrLinklayer{Protocol: htons(ethPARP), Ifindex: ifi.Index, Halen: 6}
	copy(to.Addr[:], []byte{0xff, 0xff, 0xff, 0xff, 0xff, 0xff})
	return syscall.Sendto(fd, frame, 0, to)
}

// arpAnnounceRaw: "ip is at this interface's MAC", as a request and as a
// reply (devices differ in which one updates their cache).
func arpAnnounceRaw(ifname string, ip net.IP) error {
	fd, ifi, err := arpOpen(ifname)
	if err != nil {
		return err
	}
	defer syscall.Close(fd)
	zero := make(net.HardwareAddr, 6)
	bcast := net.HardwareAddr{0xff, 0xff, 0xff, 0xff, 0xff, 0xff}
	if err := arpSend(fd, ifi, arpFrame(1, ifi.HardwareAddr, ip, ip, zero)); err != nil {
		return err
	}
	return arpSend(fd, ifi, arpFrame(2, ifi.HardwareAddr, ip, ip, bcast))
}

// arpProbeRaw: the MAC of another device that answers for ip within about
// two seconds, "" when nobody does. This node's own frames do not count.
func arpProbeRaw(ifname string, ip net.IP) (string, error) {
	fd, ifi, err := arpOpen(ifname)
	if err != nil {
		return "", err
	}
	defer syscall.Close(fd)
	tv := syscall.NsecToTimeval((200 * time.Millisecond).Nanoseconds())
	if err := syscall.SetsockoptTimeval(fd, syscall.SOL_SOCKET, syscall.SO_RCVTIMEO, &tv); err != nil {
		return "", err
	}
	probe := arpFrame(1, ifi.HardwareAddr, net.IPv4zero, ip, make(net.HardwareAddr, 6))
	want := ip.To4()
	buf := make([]byte, 128)
	for round := 0; round < 2; round++ {
		if err := arpSend(fd, ifi, probe); err != nil {
			return "", err
		}
		deadline := time.Now().Add(time.Second)
		for time.Now().Before(deadline) {
			n, _, err := syscall.Recvfrom(fd, buf, 0)
			if err != nil {
				if errors.Is(err, syscall.EAGAIN) || errors.Is(err, syscall.EINTR) {
					continue
				}
				return "", err
			}
			if mac := arpAnswerFor(buf[:n], want, ifi.HardwareAddr); mac != "" {
				return mac, nil
			}
		}
	}
	return "", nil
}

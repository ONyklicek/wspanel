package ops

import (
	"encoding/binary"
	"net"
)

// ARP frames of the floating IP (the raw socket is in arp_linux.go).

const ethPARP = 0x0806

// arpFrame: an Ethernet broadcast carrying one ARP packet.
func arpFrame(op uint16, sha net.HardwareAddr, spa, tpa net.IP, tha net.HardwareAddr) []byte {
	b := make([]byte, 42)
	copy(b[0:6], []byte{0xff, 0xff, 0xff, 0xff, 0xff, 0xff})
	copy(b[6:12], sha)
	binary.BigEndian.PutUint16(b[12:], ethPARP)
	binary.BigEndian.PutUint16(b[14:], 1)      // Ethernet
	binary.BigEndian.PutUint16(b[16:], 0x0800) // IPv4
	b[18], b[19] = 6, 4
	binary.BigEndian.PutUint16(b[20:], op)
	copy(b[22:28], sha)
	copy(b[28:32], spa.To4())
	copy(b[32:38], tha)
	copy(b[38:42], tpa.To4())
	return b
}

// arpAnswerFor: the sender's MAC when frame is an ARP packet whose sender
// address is ip (a reply, or the device announcing itself), not from own.
func arpAnswerFor(frame []byte, ip net.IP, own net.HardwareAddr) string {
	if len(frame) < 42 || binary.BigEndian.Uint16(frame[12:]) != ethPARP {
		return ""
	}
	sha := net.HardwareAddr(frame[22:28])
	spa := net.IP(frame[28:32])
	if !spa.Equal(ip) || sha.String() == own.String() {
		return ""
	}
	return sha.String()
}

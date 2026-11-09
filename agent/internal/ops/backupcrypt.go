package ops

import (
	"bufio"
	"crypto/aes"
	"crypto/cipher"
	"crypto/hkdf"
	"crypto/rand"
	"crypto/sha256"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
)

// Server backups hold the server's secrets (the panel's APP_KEY, the off-site
// credentials, DKIM keys, the local CA), so unlike site backups they are
// encrypted: AES-256-GCM over 1 MiB chunks, under a key derived from the
// backup signing key ("the recovery key", exported in the panel). Each
// chunk's nonce and associated data carry its index and whether it is the
// last one, so chunks cannot be reordered, dropped or the file cut short
// without the decryption failing.
//
//	"WSPBAK1\n" | nonce prefix (8 bytes) | { length (4 bytes) | sealed chunk }...

const (
	backupMagic = "WSPBAK1\n"
	backupChunk = 1 << 20
)

func backupCipher(recoveryKey []byte) (cipher.AEAD, error) {
	if len(recoveryKey) != 32 {
		return nil, errors.New("the recovery key must be 32 bytes")
	}
	key, err := hkdf.Key(sha256.New, recoveryKey, nil, "wspanel-server-backup-v1", 32)
	if err != nil {
		return nil, err
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, err
	}
	return cipher.NewGCM(block)
}

func chunkParams(prefix []byte, i uint32, last bool) (nonce, aad []byte) {
	nonce = make([]byte, 12)
	copy(nonce, prefix)
	binary.BigEndian.PutUint32(nonce[8:], i)
	aad = make([]byte, len(backupMagic)+5)
	copy(aad, backupMagic)
	binary.BigEndian.PutUint32(aad[len(backupMagic):], i)
	if last {
		aad[len(aad)-1] = 1
	}
	return nonce, aad
}

// encryptBackup writes r, encrypted, to w.
func encryptBackup(w io.Writer, r io.Reader, recoveryKey []byte) error {
	aead, err := backupCipher(recoveryKey)
	if err != nil {
		return err
	}
	prefix := make([]byte, 8)
	if _, err := rand.Read(prefix); err != nil {
		return err
	}
	if _, err := io.WriteString(w, backupMagic); err != nil {
		return err
	}
	if _, err := w.Write(prefix); err != nil {
		return err
	}
	br := bufio.NewReaderSize(r, backupChunk)
	buf := make([]byte, backupChunk)
	for i := uint32(0); ; i++ {
		n, err := io.ReadFull(br, buf)
		if err != nil && !errors.Is(err, io.ErrUnexpectedEOF) && !errors.Is(err, io.EOF) {
			return err
		}
		_, peekErr := br.Peek(1)
		last := peekErr != nil
		nonce, aad := chunkParams(prefix, i, last)
		sealed := aead.Seal(nil, nonce, buf[:n], aad)
		var size [4]byte
		binary.BigEndian.PutUint32(size[:], uint32(len(sealed)))
		if _, err := w.Write(size[:]); err != nil {
			return err
		}
		if _, err := w.Write(sealed); err != nil {
			return err
		}
		if last {
			return nil
		}
	}
}

// decryptBackup writes the plain content of r to w; any change to the file
// (or the wrong recovery key) is an error.
func decryptBackup(w io.Writer, r io.Reader, recoveryKey []byte) error {
	aead, err := backupCipher(recoveryKey)
	if err != nil {
		return err
	}
	head := make([]byte, len(backupMagic)+8)
	if _, err := io.ReadFull(r, head); err != nil || string(head[:len(backupMagic)]) != backupMagic {
		return errors.New("not a WSPanel server backup")
	}
	prefix := head[len(backupMagic):]
	br := bufio.NewReader(r)
	for i := uint32(0); ; i++ {
		var size [4]byte
		if _, err := io.ReadFull(br, size[:]); err != nil {
			return errors.New("the backup is cut short")
		}
		n := binary.BigEndian.Uint32(size[:])
		if n > backupChunk+uint32(aead.Overhead()) {
			return errors.New("the backup is damaged")
		}
		sealed := make([]byte, n)
		if _, err := io.ReadFull(br, sealed); err != nil {
			return errors.New("the backup is cut short")
		}
		_, peekErr := br.Peek(1)
		last := peekErr != nil
		nonce, aad := chunkParams(prefix, i, last)
		plain, err := aead.Open(nil, nonce, sealed, aad)
		if err != nil {
			return fmt.Errorf("the backup does not open with this recovery key, or it was changed (chunk %d)", i)
		}
		if _, err := w.Write(plain); err != nil {
			return err
		}
		if last {
			return nil
		}
	}
}

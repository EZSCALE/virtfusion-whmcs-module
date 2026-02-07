/**
 * VirtFusion SSH Ed25519 Key Generator
 *
 * Client-side Ed25519 keypair generation using Web Crypto API.
 * Produces OpenSSH-format public and private keys.
 */

function vfConcatArrays() {
    var total = 0;
    for (var i = 0; i < arguments.length; i++) total += arguments[i].length;
    var result = new Uint8Array(total);
    var offset = 0;
    for (var i = 0; i < arguments.length; i++) {
        result.set(arguments[i], offset);
        offset += arguments[i].length;
    }
    return result;
}

function vfSshEncodeUint32(value) {
    return new Uint8Array([
        (value >>> 24) & 0xff,
        (value >>> 16) & 0xff,
        (value >>> 8) & 0xff,
        value & 0xff
    ]);
}

function vfSshEncodeString(data) {
    if (typeof data === 'string') {
        data = new TextEncoder().encode(data);
    }
    return vfConcatArrays(vfSshEncodeUint32(data.length), data);
}

function vfArrayToBase64(uint8Array) {
    var binary = '';
    for (var i = 0; i < uint8Array.length; i++) {
        binary += String.fromCharCode(uint8Array[i]);
    }
    return btoa(binary);
}

function vfEncodeSSHPublicKey(pubKeyBytes) {
    var blob = vfConcatArrays(
        vfSshEncodeString('ssh-ed25519'),
        vfSshEncodeString(pubKeyBytes)
    );
    return 'ssh-ed25519 ' + vfArrayToBase64(blob);
}

function vfEncodeSSHPrivateKey(seed, pubKeyBytes) {
    var keyType = vfSshEncodeString('ssh-ed25519');
    var pubBlob = vfConcatArrays(keyType, vfSshEncodeString(pubKeyBytes));

    var checkInt = crypto.getRandomValues(new Uint8Array(4));
    var privKey = vfConcatArrays(seed, pubKeyBytes); // 64 bytes: seed || pubkey

    var privateSection = vfConcatArrays(
        checkInt,
        checkInt,
        vfSshEncodeString('ssh-ed25519'),
        vfSshEncodeString(pubKeyBytes),
        vfSshEncodeString(privKey),
        vfSshEncodeString('') // empty comment
    );

    // Pad to 8-byte boundary with 1,2,3,4,5...
    var padLen = 8 - (privateSection.length % 8);
    if (padLen < 8) {
        var padding = new Uint8Array(padLen);
        for (var i = 0; i < padLen; i++) padding[i] = i + 1;
        privateSection = vfConcatArrays(privateSection, padding);
    }

    var authMagic = new TextEncoder().encode('openssh-key-v1\0');
    var body = vfConcatArrays(
        authMagic,
        vfSshEncodeString('none'),   // cipher
        vfSshEncodeString('none'),   // kdf
        vfSshEncodeString(''),       // kdf options
        vfSshEncodeUint32(1),        // number of keys
        vfSshEncodeString(pubBlob),
        vfSshEncodeString(privateSection)
    );

    var b64 = vfArrayToBase64(body);
    var lines = ['-----BEGIN OPENSSH PRIVATE KEY-----'];
    for (var i = 0; i < b64.length; i += 70) {
        lines.push(b64.substring(i, i + 70));
    }
    lines.push('-----END OPENSSH PRIVATE KEY-----');
    lines.push('');
    return lines.join('\n');
}

async function vfGenerateSSHKey() {
    var keyPair = await crypto.subtle.generateKey('Ed25519', true, ['sign', 'verify']);

    var pubRaw = await crypto.subtle.exportKey('raw', keyPair.publicKey);
    var pubKeyBytes = new Uint8Array(pubRaw);

    // PKCS#8 for Ed25519 is exactly 48 bytes; bytes 16-47 are the 32-byte seed
    var privPkcs8 = await crypto.subtle.exportKey('pkcs8', keyPair.privateKey);
    var privBytes = new Uint8Array(privPkcs8);
    var seed = privBytes.slice(16, 48);

    return {
        publicKey: vfEncodeSSHPublicKey(pubKeyBytes),
        privateKey: vfEncodeSSHPrivateKey(seed, pubKeyBytes)
    };
}

function vfDownloadFile(filename, content) {
    var blob = new Blob([content], { type: 'application/octet-stream' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

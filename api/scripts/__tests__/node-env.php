<?php
declare(strict_types=1);

/**
 * Node'u başlatmak için ana ortamdan taşınması gereken değişkenler (yalnız test).
 *
 * zinesh_run_node_script() üretimde Linux'a göre dar bir env beyaz listesi kullanır.
 * Windows'ta node, SystemRoot olmadan kripto sağlayıcısını açamaz ve daha
 * başlarken çöker. Bu yalnızca testleri Windows'ta koşturmak içindir;
 * üretim davranışını değiştirmez.
 *
 * @return array<string,string>
 */
return (static function (): array {
    if (DIRECTORY_SEPARATOR !== '\\') {
        return [];
    }
    $env = [];
    foreach (['SystemRoot', 'windir', 'TEMP', 'TMP', 'SystemDrive', 'COMSPEC'] as $key) {
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            $env[$key] = $val;
        }
    }
    return $env;
})();

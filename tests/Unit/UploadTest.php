<?php

declare(strict_types=1);

use App\Services\UploadService;

$fake = static function (string $content, string $name): array {
    $path = tempnam(sys_get_temp_dir(), 'hgu');
    file_put_contents($path, $content);
    return ['name' => $name, 'type' => 'image/jpeg', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
};

test('security: PHP code renamed to .jpg, SVG and HTML uploads are refused', function () use ($fake) {
    $dir = sys_get_temp_dir() . '/hg-upload-test';
    assert_throws(DomainException::class, fn () => UploadService::storeImage($fake('<?php echo "not an image"; ?>', 'x.jpg'), $dir, 4 << 20), 'Only JPG');
    assert_throws(DomainException::class, fn () => UploadService::storeImage($fake('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'x.svg'), $dir, 4 << 20), 'Only JPG');
    assert_throws(DomainException::class, fn () => UploadService::storeImage($fake('<html><script>alert(1)</script></html>', 'x.png'), $dir, 4 << 20), 'Only JPG');
});

test('security: oversized images are refused; a real image is re-encoded under a random name', function () use ($fake) {
    $dir = sys_get_temp_dir() . '/hg-upload-test';
    $img = imagecreatetruecolor(10, 10);
    ob_start();
    imagepng($img);
    $png = (string) ob_get_clean();
    assert_throws(DomainException::class, fn () => UploadService::storeImage($fake($png, 'a.png'), $dir, 10), 'smaller');
    $name = UploadService::storeImage($fake($png . '<?php echo 1; ?>', 'evil.php.png'), $dir, 4 << 20);
    assert_true((bool) preg_match('/^[a-f0-9]{32}\.png$/', $name), 'random .png name');
    assert_true(!str_contains((string) file_get_contents($dir . '/' . $name), '<?php'), 'appended code stripped by re-encoding');
});

<?php

namespace Tests\Feature;

use App\Shared\Infrastructure\CloudinaryUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sin CLOUDINARY_URL configurado (como en producción hoy), las subidas de
 * imágenes deben seguir funcionando guardando el archivo en disco local en
 * vez de fallar silenciosamente — antes, cualquier subida de imagen de
 * producto/servicio/galería no hacía nada visible para el usuario.
 */
class SubidaImagenLocalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['services.cloudinary.url' => null]); // asegura que esté "no configurado" para este test
    }

    /** Un PNG real de 1x1 (no basta con nombrar el archivo .png: la extensión se detecta por el contenido). */
    private function crearPngTemporal(): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'img');
        $imagen = imagecreatetruecolor(1, 1);
        imagepng($imagen, $ruta);
        imagedestroy($imagen);
        return $ruta;
    }

    private function crearJpgTemporal(): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'img');
        $imagen = imagecreatetruecolor(1, 1);
        imagejpeg($imagen, $ruta);
        imagedestroy($imagen);
        return $ruta;
    }

    public function test_sube_localmente_cuando_cloudinary_no_esta_configurado(): void
    {
        $archivoTemp = $this->crearPngTemporal();

        $resultado = (new CloudinaryUploader())->subir($archivoTemp, 'logix/productos/producto_1');

        $this->assertArrayHasKey('secure_url', $resultado);
        $this->assertArrayHasKey('public_id', $resultado);
        $this->assertSame('logix/productos/producto_1', $resultado['public_id']);
        Storage::disk('public')->assertExists('uploads/logix/productos/producto_1.png');

        unlink($archivoTemp);
    }

    public function test_resubir_el_mismo_public_id_reemplaza_el_archivo_anterior(): void
    {
        $uploader = new CloudinaryUploader();

        $a = $this->crearPngTemporal();
        $uploader->subir($a, 'logix/productos/producto_9');
        Storage::disk('public')->assertExists('uploads/logix/productos/producto_9.png');

        $b = $this->crearJpgTemporal();
        $uploader->subir($b, 'logix/productos/producto_9');

        Storage::disk('public')->assertExists('uploads/logix/productos/producto_9.jpg');
        Storage::disk('public')->assertMissing('uploads/logix/productos/producto_9.png');

        unlink($a); unlink($b);
    }

    public function test_borrar_quita_el_archivo_local(): void
    {
        $uploader = new CloudinaryUploader();
        $archivo = $this->crearJpgTemporal();
        $uploader->subir($archivo, 'logix/servicios/servicio_5/galeria/abc123');

        $uploader->borrar('logix/servicios/servicio_5/galeria/abc123');

        Storage::disk('public')->assertMissing('uploads/logix/servicios/servicio_5/galeria/abc123.jpg');
        unlink($archivo);
    }
}

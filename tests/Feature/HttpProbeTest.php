<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpProbeTest extends TestCase
{
    public function test_double_fake()
    {
        Http::fake(['*/message/sendText/*' => Http::response(['a' => 1], 200), '*' => Http::response([], 200)]);
        Http::fake(['*/message/sendText/*' => Http::response(['error' => 'x'], 500), '*' => Http::response([], 500)]);
        $res = Http::post('http://localhost:8080/message/sendText/abc', []);
        fwrite(STDERR, "\nSTATUS: " . $res->status() . "\n");
        $this->assertTrue(true);
    }
}

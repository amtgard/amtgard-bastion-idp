<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Services\Mail;

use Amtgard\IdP\Services\Mail\StreamSmtpPipe;
use PHPUnit\Framework\TestCase;

final class StreamSmtpPipeTest extends TestCase
{
    public function testReadWriteAndFailedTlsUseTheStream(): void
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($server, "250-hello\r\n250 OK\r\n");
        $pipe = new StreamSmtpPipe($client);

        $this->assertSame("250-hello\n250 OK", $pipe->readResponse());
        $pipe->write("EHLO localhost\r\n");
        $this->assertSame("EHLO localhost\r\n", fread($server, 64));

        $this->expectException(\RuntimeException::class);
        $pipe->startTls();
    }

    public function testReadThrowsWhenTheStreamCloses(): void
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fclose($server);
        $pipe = new StreamSmtpPipe($client);

        $this->expectException(\RuntimeException::class);
        $pipe->readResponse();
    }

    public function testWriteThrowsWhenTheStreamCloses(): void
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fclose($server);
        $pipe = new StreamSmtpPipe($client);

        $this->expectException(\RuntimeException::class);
        $pipe->write("EHLO localhost\r\n");
    }

    public function testConnectFailsWhenNothingIsListening(): void
    {
        $this->expectException(\RuntimeException::class);
        StreamSmtpPipe::connect('127.0.0.1', 1, 1);
    }

    public function testConnectReadsTheServerGreeting(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);

        $pipe = StreamSmtpPipe::connect('127.0.0.1', $port, 2);
        $accepted = stream_socket_accept($server, 2);
        $this->assertIsResource($accepted);
        fwrite($accepted, "220 ready\r\n");

        $this->assertSame('220 ready', $pipe->readResponse());
        fclose($accepted);
        fclose($server);
    }

    public function testStartTlsCanBeReplaced(): void
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pipe = new StreamSmtpPipe($client, static fn (): bool => true);
        $pipe->startTls();
        fclose($server);
        $this->assertTrue(true);
    }
}

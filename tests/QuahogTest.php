<?php

namespace Xenolope\Quahog\Tests;

use PHPUnit\Framework\TestCase;
use Socket\Raw\Socket;
use Xenolope\Quahog\Client;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use Xenolope\Quahog\Exception\ConnectionException;

class QuahogTest extends TestCase
{
    /**
     * @var \Socket\Raw\Socket|\PHPUnit_Framework_MockObject_MockObject
     */
    private $socket;

    /**
     * @var Client|\PHPUnit_Framework_MockObject_MockObject
     */
    private $quahog;

    /**
     * @var vfsStreamDirectory
     */
    private $root;

    public function setUp()
    {
        $this->socket = $this->createMock(Socket::class);
        $this->quahog = new Client($this->socket, 30, PHP_NORMAL_READ);
        $this->root = vfsStream::setup('tmp');
    }

    public function testPingOK()
    {
        $this->socket->expects($this->once())->method('selectRead')->willReturn(true);
        $this->socket->expects($this->any())->method('read')->willReturn("PONG\n");

        $result = $this->quahog->ping();

        self::assertTrue($result);
    }

    public function testPingFail()
    {
        $this->expectException(ConnectionException::class);

        $this->socket->expects($this->once())->method('selectRead')->willReturn(true);
        $this->socket->expects($this->any())->method('read')->willReturn('');

        $result = $this->quahog->ping();

        self::assertTrue($result);
    }

    public function testVersion()
    {
        $this->socket->expects($this->once())->method('selectRead')->willReturn(true);
        $this->socket->expects($this->any())->method('read')->willReturn("ClamAV 1.2.3\n");

        $result = $this->quahog->version();

        self::assertStringStartsWith('ClamAV', $result);
    }

    public function testStats()
    {
        $this->socket->expects($this->any())->method('selectRead')->willReturnOnConsecutiveCalls(true, true, true, false);
        $this->socket->expects($this->any())->method('read')->willReturnOnConsecutiveCalls("POOLS:\n", "BLA\n", "END\n");

        $result = $this->quahog->stats();

        self::assertStringStartsWith('POOLS:', $result);
    }

    public function testReload()
    {
        $this->socket->expects($this->once())->method('selectRead')->willReturn(true);
        $this->socket->expects($this->any())->method('read')->willReturn("RELOADING\n");

        $result = $this->quahog->reload();

        self::assertSame('RELOADING', $result);
    }

    public function testScanFile()
    {
        $this->socket->expects($this->once())->method('selectRead')->willReturn(true);
        $this->socket->expects($this->any())->method('read')->willReturn("/tmp/EICAR: Eicar-Test-Signature FOUND\n");

        $result = $this->quahog->scanFile('/tmp/EICAR');

        self::assertSame(
            array('filename' => '/tmp/EICAR', 'reason' => 'Eicar-Test-Signature', 'status' => 'FOUND'),
            $result
        );
    }

    public function testMultiscanFile()
    {
        $this->socket->expects($this->once())->method('selectRead')->willReturn(true);
        $this->socket->expects($this->any())->method('read')->willReturn("/tmp/quahog/EICAR: Eicar-Test-Signature FOUND\n");

        $result = $this->quahog->multiscanFile('/tmp/quahog');

        self::assertSame('Eicar-Test-Signature', $result['reason']);
        self::assertSame('FOUND', $result['status']);
    }

    public function testContScan()
    {
        $this->socket->expects($this->once())->method('selectRead')->willReturn(true);
        $this->socket->expects($this->any())->method('read')->willReturn("/tmp/quahog/EICAR: Eicar-Test-Signature FOUND\n");

        $result = $this->quahog->contScan('/tmp/quahog');

        self::assertSame(
            ['filename' => '/tmp/quahog/EICAR', 'reason' => 'Eicar-Test-Signature', 'status' => 'FOUND'],
            $result
        );
    }

    public function testScanLocalFile()
    {
        $file = vfsStream::newFile('EICAR')
            ->withContent('/tmp/EICAR: Eicar-Test-Signature FOUND')
            ->at($this->root);

        $this->socket->expects($this->once())->method('selectRead')->willReturn(true);
        $this->socket->expects($this->any())->method('read')->willReturn($file->url() . ": Eicar-Test-Signature FOUND\n");

        $result = $this->quahog->scanLocalFile($file->url());

        self::assertSame(
            ['filename' => $file->url(), 'reason' => 'Eicar-Test-Signature', 'status' => 'FOUND'],
            $result
        );
    }

    public function testScanStream()
    {
        $this->socket->expects($this->once())->method('selectRead')->willReturn(true);
        $this->socket->expects($this->any())->method('read')->willReturn("stream: Eicar-Test-Signature FOUND\n");

        $result = $this->quahog->scanStream('stream');

        self::assertSame(
            ['filename' => 'stream', 'reason' => 'Eicar-Test-Signature', 'status' => 'FOUND'],
            $result
        );
    }

    public function testShutdown()
    {
        $this->socket->expects($this->once())->method('selectRead')->willReturn(true);
        $this->socket->expects($this->any())->method('read')->willReturn('');
        $result = $this->quahog->shutdown();

        self::assertSame('', $result);
    }

    public function testSession() {
        $this->socket->expects($this->any())->method('close')->willThrowException(new \Exception("Closed connection!"));
        $this->socket->expects($this->any())->method('selectRead')->willReturnOnConsecutiveCalls(true, true, true, true, false);
        $this->socket->expects($this->any())->method('send')
            ->withConsecutive([$this->equalTo("nIDSESSION\n"), $this->anything()],
                [$this->equalTo("nVERSION\n"), $this->anything()],
                [$this->equalTo("nSTATS\n"), $this->anything()]);
        $this->socket->expects($this->any())->method('read')->willReturnOnConsecutiveCalls("1: bla\n", "2: bla\n", "bla\n", "END\n");

        $this->quahog->startSession();

        self::assertEquals('bla', $this->quahog->version());
        self::assertEquals("bla\nbla\nEND", $this->quahog->stats());

        $this->quahog->endSession();
    }
}

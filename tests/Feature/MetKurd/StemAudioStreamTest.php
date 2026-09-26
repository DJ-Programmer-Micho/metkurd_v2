<?php

use App\Models\Customer;
use App\Models\MlJob;
use App\Services\Media\StemAudioStream;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('stem/track.mp3', '0123456789');
});

it('serves exact full, bounded, open and suffix bytes without redirecting', function (?string $range, int $status, string $body, ?string $contentRange) {
    $request = Request::create('/audio');
    if ($range !== null) {
        $request->headers->set('Range', $range);
    }
    $response = app(StemAudioStream::class)->response($request, 's3', 'stem/track.mp3', 'audio/mpeg');
    expect($response->getStatusCode())->toBe($status)
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen($body))
        ->and($response->headers->get('Content-Range'))->toBe($contentRange)
        ->and($response->headers->get('Accept-Ranges'))->toBe('bytes')
        ->and($response->headers->get('Content-Type'))->toBe('audio/mpeg')
        ->and($response->headers->get('Location'))->toBeNull()
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'immutable');
    ob_start();
    $response->sendContent();
    expect(ob_get_clean())->toBe($body);
})->with([
    [null, 200, '0123456789', null],
    ['bytes=2-5', 206, '2345', 'bytes 2-5/10'],
    ['bytes=7-', 206, '789', 'bytes 7-9/10'],
    ['bytes=-3', 206, '789', 'bytes 7-9/10'],
    ['bytes=8-100', 206, '89', 'bytes 8-9/10'],
    ['bytes=-100', 206, '0123456789', 'bytes 0-9/10'],
    ['bytes=10-', 416, '', 'bytes */10'],
    ['bytes=6-2', 416, '', 'bytes */10'],
    ['bytes=-0', 416, '', 'bytes */10'],
    ['bytes=0-1,4-5', 200, '0123456789', null],
    ['bytes=invalid', 200, '0123456789', null],
]);

it('answers HEAD without opening a stream and handles If-Range conservatively', function () {
    $request = Request::create('/audio', 'HEAD');
    $request->headers->set('Range', 'bytes=2-5');
    $response = app(StemAudioStream::class)->response($request, 's3', 'stem/track.mp3', 'audio/mpeg');
    expect($response->getStatusCode())->toBe(200)->and($response->headers->get('Content-Length'))->toBe('10')
        ->and($response->getContent())->toBe('');
    $request = Request::create('/audio');
    $request->headers->set('Range', 'bytes=2-5');
    $request->headers->set('If-Range', '"old-validator"');
    $response = app(StemAudioStream::class)->response($request, 's3', 'stem/track.mp3', 'audio/mpeg');
    expect($response->getStatusCode())->toBe(200)->and($response->headers->get('Content-Range'))->toBeNull();
    ob_start();
    $response->sendContent();
    expect(ob_get_clean())->toBe('0123456789');
});

it('forwards a bounded range to S3 with streaming enabled and closes its body', function () {
    $body = Utils::streamFor('2345');
    $handler = new MockHandler([function ($command) use ($body) {
        expect($command->getName())->toBe('GetObject')->and($command['Bucket'])->toBe('fixture-bucket')
            ->and($command['Key'])->toBe('prefix/stem/track.mp3')->and($command['Range'])->toBe('bytes=2-5')
            ->and($command['@http']['stream'])->toBeTrue();

        return new Result(['Body' => $body, 'ContentRange' => 'bytes 2-5/10']);
    }]);
    $client = new S3Client(['version' => 'latest', 'region' => 'us-east-1', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'handler' => $handler]);
    $disk = Mockery::mock(AwsS3V3Adapter::class);
    $disk->shouldReceive('exists')->with('stem/track.mp3')->once()->andReturnTrue();
    $disk->shouldReceive('size')->with('stem/track.mp3')->once()->andReturn(10);
    $disk->shouldReceive('getConfig')->andReturn(['bucket' => 'fixture-bucket']);
    $disk->shouldReceive('path')->with('stem/track.mp3')->andReturn('prefix/stem/track.mp3');
    $disk->shouldReceive('getClient')->andReturn($client);
    $disk->shouldNotReceive('readStream');
    Storage::set('range-fixture', $disk);
    $request = Request::create('/audio');
    $request->headers->set('Range', 'bytes=2-5');
    $response = app(StemAudioStream::class)->response($request, 'range-fixture', 'stem/track.mp3', 'audio/mpeg');
    ob_start();
    $response->sendContent();
    expect(ob_get_clean())->toBe('2345');
    expect($body->isReadable())->toBeFalse();
});

it('keeps V2 route ownership and missing-file errors while serving same-origin ranges', function () {
    $this->seed();
    config(['metkurd_v2.enabled' => true]);
    $owner = Customer::create(['username' => 'stem-stream', 'email' => 'stem-stream@example.test', 'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    $job = MlJob::create(['id' => (string) Str::uuid(), 'customer_id' => $owner->id, 'job_kind' => 'stem', 'status' => 'done',
        'input' => ['workspace' => 'stem_v2', 'separation_mode' => 4], 'output' => ['disk' => 's3', 'stems' => ['vocals' => ['path' => 'stem/track.mp3', 'mime' => 'audio/mpeg']]]]);
    $url = route('app.v2.stem.stream', ['locale' => 'en', 'jobId' => $job->id, 'track' => 'vocals']);
    $this->actingAs($owner, 'app')->get($url, ['Range' => 'bytes=2-5'])->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 2-5/10')->assertHeaderMissing('Location')->assertStreamedContent('2345');
    $other = Customer::create(['username' => 'other-stream', 'email' => 'other-stream@example.test', 'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true]);
    $this->actingAs($other, 'app')->get($url)->assertNotFound();
    Storage::disk('s3')->delete('stem/track.mp3');
    $this->actingAs($owner, 'app')->get($url)->assertNotFound();
});

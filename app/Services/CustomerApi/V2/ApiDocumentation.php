<?php

namespace App\Services\CustomerApi\V2;

use App\Services\MetKurd\V2\InputBoundary;
use App\Services\OCR\OcrDocumentProbe;

/** Executable request shapes kept alongside the public API contract. */
class ApiDocumentation
{
    public function services(): array
    {
        return [
            'apollo' => ['name' => 'Apollo', 'endpoint' => 'speech', 'description' => 'Generate speech with an available voice.', 'data' => ['text' => 'سڵاو لە مێتکوردەوە', 'voice' => 'VOICE_ID', 'model' => '2.0', 'language' => 'ckb'], 'file' => null],
            'vector' => ['name' => 'Vector', 'endpoint' => 'voice-clone', 'description' => 'Generate speech from a reference voice you own.', 'data' => ['text' => 'سڵاو لە مێتکوردەوە', 'model' => '2.0', 'language' => 'ckb'], 'file' => 'reference.wav'],
            'leo' => ['name' => 'Leo', 'endpoint' => 'transcriptions', 'description' => 'Turn audio into a transcript.', 'data' => ['language' => 'ckb', 'intelligent' => '0'], 'file' => 'audio.wav'],
            'caption' => ['name' => 'Caption', 'endpoint' => 'captions', 'description' => 'Create a transcript, subtitles and timed segments.', 'data' => ['language' => 'ckb', 'intelligent' => '0'], 'file' => 'audio.wav'],
            'ocr' => ['name' => 'OCR', 'endpoint' => 'ocr', 'description' => 'Extract text and exports from a PDF or image.', 'data' => ['pages' => 'all', 'exports[0]' => 'txt', 'exports[1]' => 'docx', 'intelligent' => '0'], 'file' => 'document.pdf'],
            'stem-2' => ['name' => 'STEM 2', 'endpoint' => 'stem', 'description' => 'Separate vocals and accompaniment.', 'data' => ['mode' => '2'], 'file' => 'audio.wav'],
            'stem-4' => ['name' => 'STEM 4', 'endpoint' => 'stem', 'description' => 'Separate vocals, drums, bass and other audio.', 'data' => ['mode' => '4'], 'file' => 'audio.wav'],
            'zeta' => ['name' => 'Zeta 1.0', 'endpoint' => 'zeta', 'description' => 'api_v2.zeta_description', 'file' => null, 'data' => ['segments' => [
                ['voice' => 'VOICE_ID', 'language' => 'ckb', 'text' => 'سڵاو لە مێتکوردەوە', 'pause_after_ms' => 500],
                ['voice' => 'ANOTHER_VOICE_ID', 'language' => 'ckb', 'text' => 'بەخێربێن', 'pause_after_ms' => 0],
            ]]],
            'theta' => ['name' => 'Theta 1.0', 'endpoint' => 'theta', 'description' => 'api_v2.theta_description', 'file' => null, 'data' => ['segments' => [
                ['reference_id' => 123, 'language' => 'ckb', 'text' => 'سڵاو لە مێتکوردەوە', 'pause_after_ms' => 1000],
                ['reference_id' => 123, 'language' => 'ckb', 'text' => 'بەخێربێن', 'pause_after_ms' => 0],
            ]]],
            'harakat' => ['name' => 'Harakat 1.0', 'endpoint' => 'harakat', 'description' => 'api_v2.harakat_description', 'file' => null, 'data' => ['text' => 'مرحبا بكم في منصة ميت كورد']],
        ];
    }

    public function contract(array $service): ?array
    {
        return app(ApiCatalog::class)->additionalServices()[$service['endpoint']] ?? null;
    }

    public function voiceResponseExample(): string
    {
        return json_encode(['voices' => [
            ['id' => 'VOICE_ID', 'name' => 'Voice Name'],
            ['id' => 'ANOTHER_VOICE_ID', 'name' => 'Another Voice'],
        ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function voiceExamples(): array
    {
        $url = url('/api/v2/voices');

        return [
            'cURL' => "curl -X GET \"{$url}\" \\\n  -H 'Authorization: Bearer YOUR_API_KEY' \\\n  -H 'Accept: application/json'",
            'PHP' => "<?php\n\$ch = curl_init('{$url}');\ncurl_setopt_array(\$ch, [\n  CURLOPT_HTTPHEADER => ['Authorization: Bearer YOUR_API_KEY', 'Accept: application/json'],\n  CURLOPT_RETURNTRANSFER => true,\n]);\n\$response = curl_exec(\$ch);\nif (\$response === false) throw new RuntimeException('Request failed');\necho curl_getinfo(\$ch, CURLINFO_HTTP_CODE) . PHP_EOL . \$response;\ncurl_close(\$ch);",
            'Python' => "import requests\n\nresponse = requests.get('{$url}', headers={\n    'Authorization': 'Bearer YOUR_API_KEY',\n    'Accept': 'application/json',\n}, timeout=30)\nprint(response.status_code, response.json())",
            'JavaScript' => "const response = await fetch('{$url}', {\n  method: 'GET',\n  headers: {\n    Authorization: 'Bearer YOUR_API_KEY',\n    Accept: 'application/json',\n  },\n});\nconsole.log(response.status, await response.json());",
        ];
    }

    public function responseExample(string $service): string
    {
        $text = $service === 'harakat';
        $diacritized = 'مَرْحَبًا بِكُمْ فِي مِنَصَّةِ مِيت كُورْد';
        $result = ['files' => [['id' => 'file_YOUR_FILE_ID', 'kind' => $text ? 'diacritized_text' : 'render',
            'mime_type' => $text ? 'text/plain; charset=UTF-8' : 'audio/wav', 'size_bytes' => $text ? strlen($diacritized) : 96044,
            'download_url' => url('/api/v2/files/file_YOUR_FILE_ID/download')]]];
        $result += $text ? ['text' => $diacritized, 'characters' => mb_strlen($diacritized), 'words' => 6, 'lines' => 1, 'chunks' => 1]
            : ['segment_count' => 2, 'total_chars' => mb_strlen('سڵاو لە مێتکوردەوەبەخێربێن'), 'duration' => 3.0];

        return json_encode(['id' => 'job_YOUR_JOB_ID', 'status' => 'completed', 'service' => $service,
            'created_at' => '2026-09-26T12:00:00Z', 'completed_at' => '2026-09-26T12:00:10Z',
            'expires_at' => '2026-10-03T12:00:00Z', 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function examples(array $service): array
    {
        if ($service['endpoint'] === 'ocr') {
            return $this->ocrExamples();
        }
        $url = url('/api/v2/'.$service['endpoint']);
        $data = $service['data'];
        $data['storage_mode'] = 'temporary';
        $file = $service['file'];
        $headers = ['Authorization: Bearer YOUR_API_KEY', 'Accept: application/json', 'Idempotency-Key: UNIQUE_REQUEST_ID'];
        $curl = 'curl -X POST "'.$url.'"';
        foreach ($headers as $header) {
            $curl .= " \\\n  -H '".$header."'";
        }
        if ($file) {
            foreach ($data as $key => $value) {
                $curl .= " \\\n  -F '".$key.'='.$value."'";
            }
            $curl .= " \\\n  -F 'file=@".$file."'";
        } else {
            $curl .= " \\\n  -H 'Content-Type: application/json' \\\n  -d '".json_encode($data, JSON_UNESCAPED_SLASHES)."'";
        }
        $phpData = var_export($data, true);
        $php = "<?php\n\$headers = ".var_export($headers, true).";\n\$data = ".$phpData.";\n";
        $php .= $file ? "\$data['file'] = new CURLFile(__DIR__ . '/".$file."');\n" : "\$headers[] = 'Content-Type: application/json';\n\$data = json_encode(\$data, JSON_THROW_ON_ERROR);\n";
        $php .= "\$ch = curl_init('".$url."');\ncurl_setopt_array(\$ch, [\n  CURLOPT_POST => true, CURLOPT_HTTPHEADER => \$headers,\n  CURLOPT_POSTFIELDS => \$data, CURLOPT_RETURNTRANSFER => true,\n]);\n\$response = curl_exec(\$ch);\nif (\$response === false) throw new RuntimeException('Request failed');\necho curl_getinfo(\$ch, CURLINFO_HTTP_CODE) . PHP_EOL . \$response;\ncurl_close(\$ch);";
        $jsonHeaders = json_encode(['Authorization' => 'Bearer YOUR_API_KEY', 'Accept' => 'application/json', 'Idempotency-Key' => 'UNIQUE_REQUEST_ID'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $python = "import requests\n\nheaders = ".$jsonHeaders."\ndata = ".$jsonData."\n";
        $python .= $file ? "with open('".$file."', 'rb') as upload:\n    response = requests.post('".$url."', headers=headers, data=data, files={'file': upload}, timeout=90)\n" : "response = requests.post('".$url."', headers=headers, json=data, timeout=90)\n";
        $python .= 'print(response.status_code, response.json())';
        $javascript = ($file ? "import { readFile } from 'node:fs/promises';\n\n" : '').'const headers = '.$jsonHeaders.";\nconst data = ".$jsonData.";\n";
        $javascript .= $file ? "const body = new FormData();\nfor (const [key, value] of Object.entries(data)) body.append(key, value);\nbody.append('file', new Blob([await readFile('".$file."')]), '".$file."');\n" : "headers['Content-Type'] = 'application/json';\nconst body = JSON.stringify(data);\n";
        $javascript .= "const response = await fetch('".$url."', { method: 'POST', headers, body });\nconsole.log(response.status, await response.json());";

        return ['cURL' => $curl, 'PHP' => $php, 'Python' => $python, 'JavaScript' => $javascript];
    }

    public function ocrContract(): array
    {
        return [
            'extensions' => InputBoundary::DOCUMENT_EXTENSIONS,
            'max_mib' => InputBoundary::DOCUMENT_MAX_KIB / 1024,
            'max_pages' => OcrDocumentProbe::MAX_PAGES,
            'exports' => ApiSubmission::OCR_EXPORTS,
            'default_exports' => ApiSubmission::OCR_DEFAULT_EXPORTS,
            'temporary_days' => (int) config('customer_api.temporary_file_ttl_days', 7),
        ];
    }

    private function ocrExamples(): array
    {
        $url = url('/api/v2/ocr');
        $headers = ['Authorization: Bearer YOUR_API_KEY', 'Accept: application/json', 'Idempotency-Key: UNIQUE_REQUEST_ID'];
        $fields = ['pages=1', 'exports[]=txt', 'exports[]=docx', 'intelligent=1', 'storage_mode=temporary'];
        $curl = 'curl -X POST "'.$url.'"';
        $powershell = 'curl.exe -X POST "'.$url.'"';
        foreach ($headers as $header) {
            $curl .= " \\\n  -H '".$header."'";
            $powershell .= " `\n  -H \"".$header.'"';
        }
        foreach ($fields as $field) {
            $curl .= " \\\n  -F '".$field."'";
            $powershell .= " `\n  -F \"".$field.'"';
        }
        $curl .= " \\\n  -F 'file=@./document.pdf'";
        $powershell .= " `\n  -F \"file=@C:\\Documents\\document.pdf\"";
        $php = str_replace('OCR_ENDPOINT', $url, <<<'PHP'
<?php
$data = [
    'pages' => '1',
    'exports[0]' => 'txt',
    'exports[1]' => 'docx',
    'intelligent' => '1',
    'storage_mode' => 'temporary',
    'file' => new CURLFile(__DIR__ . '/document.pdf', 'application/pdf', 'document.pdf'),
];
$ch = curl_init('OCR_ENDPOINT');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer YOUR_API_KEY',
        'Accept: application/json',
        'Idempotency-Key: UNIQUE_REQUEST_ID',
    ],
    CURLOPT_POSTFIELDS => $data,
    CURLOPT_RETURNTRANSFER => true,
]);
$response = curl_exec($ch);
if ($response === false) throw new RuntimeException('Request failed');
echo curl_getinfo($ch, CURLINFO_HTTP_CODE) . PHP_EOL . $response;
curl_close($ch);
PHP);
        $python = str_replace('OCR_ENDPOINT', $url, <<<'PYTHON'
import requests

headers = {
    'Authorization': 'Bearer YOUR_API_KEY',
    'Accept': 'application/json',
    'Idempotency-Key': 'UNIQUE_REQUEST_ID',
}
data = {
    'pages': '1',
    'exports[]': ['txt', 'docx'],
    'intelligent': '1',
    'storage_mode': 'temporary',
}
with open('document.pdf', 'rb') as f:
    files = {'file': ('document.pdf', f, 'application/pdf')}
    response = requests.post('OCR_ENDPOINT', headers=headers, data=data, files=files, timeout=90)
print(response.status_code, response.json())
PYTHON);
        $node = str_replace('OCR_ENDPOINT', $url, <<<'JS'
import { readFile } from 'node:fs/promises';

const body = new FormData();
body.append('pages', '1');
body.append('exports[]', 'txt');
body.append('exports[]', 'docx');
body.append('intelligent', '1');
body.append('storage_mode', 'temporary');
body.append('file', new Blob([await readFile('./document.pdf')], {type: 'application/pdf'}), 'document.pdf');
const response = await fetch('OCR_ENDPOINT', {
  method: 'POST',
  headers: {
    Authorization: 'Bearer YOUR_API_KEY',
    Accept: 'application/json',
    'Idempotency-Key': 'UNIQUE_REQUEST_ID',
  },
  body,
});
console.log(response.status, await response.json());
JS);

        return ['cURL' => $curl, 'PowerShell' => $powershell, 'PHP' => $php, 'Python' => $python, 'JavaScript' => $node];
    }

    public function ocrFollowupExamples(): array
    {
        return [
            'poll' => 'curl "'.url('/api/v2/jobs/job_YOUR_JOB_ID')."\" \\\n  -H 'Authorization: Bearer YOUR_API_KEY' \\\n  -H 'Accept: application/json'",
            'download' => 'curl "'.url('/api/v2/files/file_YOUR_FILE_ID/download')."\" \\\n  -H 'Authorization: Bearer YOUR_API_KEY' \\\n  -o result.txt",
        ];
    }
}

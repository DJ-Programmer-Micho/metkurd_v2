<?php

namespace App\Services\CustomerApi\V2;

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
        ];
    }

    public function examples(array $service): array
    {
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
}

<div x-cloak x-show="section === 'ocr'" x-data="{ ocrLanguage: 'cURL' }" data-api-ocr-examples>
    <p class="api-code-note">{{ __('api_v2.ocr_examples_note') }}</p>
    <label class="visually-hidden" for="api-code-ocr">{{ __('Example language') }}</label>
    <select id="api-code-ocr" class="form-select mb-3" x-model="ocrLanguage">
        <option>cURL</option><option>PowerShell</option><option>PHP</option><option>Python</option><option value="JavaScript">Node.js</option>
    </select>
    @foreach($documentation->examples($services['ocr']) as $language => $example)
        <div x-show="ocrLanguage === @js($language)">
            <button class="api-copy" @click="copy($refs['code-ocr-{{ $loop->index }}'].textContent)">{{ __('Copy') }}</button>
            <pre dir="ltr"><code x-ref="code-ocr-{{ $loop->index }}">{{ $example }}</code></pre>
        </div>
    @endforeach
</div>

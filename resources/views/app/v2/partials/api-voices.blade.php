<section id="available-voices" x-cloak x-show="section === 'voices'">
    <h2>{{ __('api_v2.available_voices') }}</h2>
    <p>{{ __('api_v2.voices_intro') }}</p>
    <div class="api-endpoint" dir="ltr"><b>GET</b> /api/v2/voices</div>
    <p>{{ __('api_v2.voices_endpoint') }}</p>
    <pre dir="ltr">Authorization: Bearer YOUR_API_KEY</pre>
    <p>{{ __('api_v2.voices_permissions') }}</p>
    <h3 class="h5">{{ __('api_v2.your_voices') }}</h3>
    <p>{{ __('api_v2.voices_refresh') }}</p>
    <p>{{ __('api_v2.preview_free') }}</p>
    <span role="status" x-show="copied" x-cloak>{{ __('Copied') }}</span>
    <div class="api-table"><table>
        <thead><tr><th>{{ __('Name') }}</th><th>{{ __('api_v2.voice_id') }}</th><th>{{ __('api_v2.voice_preview') }}</th></tr></thead>
        <tbody>
            @forelse($this->availableVoices as $voice)
                <tr wire:key="api-voice-{{ $voice['code'] }}">
                    <td>
                        @if($voice['avatar_url'])<img src="{{ $voice['avatar_url'] }}?proxy=1" alt="" width="40" height="40" loading="lazy" class="rounded-circle me-2">@endif
                        <span dir="auto">{{ $voice['name'] }}</span>
                    </td>
                    <td><code dir="ltr">{{ $voice['code'] }}</code><br><button type="button" class="btn btn-sm btn-outline-primary mt-2" @click="copy(@js($voice['code']))">{{ __('api_v2.copy_voice_id') }}</button></td>
                    <td>
                        @if($voice['preview_url'])
                            <audio controls preload="none" src="{{ $voice['preview_url'] }}?proxy=1" aria-label="{{ __('api_v2.preview_voice', ['name' => $voice['name']]) }}" style="width:180px;max-width:100%"></audio>
                        @else
                            <span>{{ __('api_v2.preview_unavailable') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="3">{{ __('api_v2.no_voices') }}</td></tr>
            @endforelse
        </tbody>
    </table></div>
    <h3 class="h5">{{ __('api_v2.voices_response') }}</h3>
    <p>{{ __('api_v2.voices_response_note') }}</p>
    <pre dir="ltr">{{ $documentation->voiceResponseExample() }}</pre>
</section>

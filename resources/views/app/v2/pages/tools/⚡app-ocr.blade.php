<?php

use App\Models\MlJob;
use App\Services\MetKurd\Jobs\OcrV2SubmissionService;
use App\Services\OCR\OcrJobSyncService;
use App\Services\Security\JobExecutionLockService;
use App\Services\Storage\CustomerOutputStorage;
use App\Support\MetKurdV2JobStatusPresentation;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('app::v2.layouts.app')] class extends Component {
    use WithFileUploads; use WithPagination;
    protected const EXTENSIONS=['pdf','png','jpg','jpeg','webp','bmp','gif','tif','tiff'];
    public $documentFile=null; public ?string $documentFileName=null,$documentFileMime=null,$documentFileExt=null,$documentHash=null;
    public ?int $documentFileBytes=null,$clientPdfPageCount=null; public string $pageMode='all',$pageRange='', $submissionError='', $search='';
    public bool $runLlmCorrector=true,$exportDocx=true,$exportTxt=true,$exportMarkdown=false,$exportHtml=false,$exportZip=false;
    public ?string $currentJobId=null; public bool $showJobStatus=false; protected $paginationTheme='bootstrap';
    public function mount():void{$this->hydrateCurrentJob();}
    protected function rules():array{return ['documentFile'=>'required|file|mimes:pdf,png,jpg,jpeg,webp,bmp,gif,tif,tiff|max:204800','pageMode'=>'required|in:all,custom','pageRange'=>'nullable|string|max:255','runLlmCorrector'=>'boolean','exportDocx'=>'boolean','exportTxt'=>'boolean','exportMarkdown'=>'boolean','exportHtml'=>'boolean','exportZip'=>'boolean'];}
    public function updatedDocumentFile():void{$this->submissionError='';$this->validateOnly('documentFile');if(!$this->documentFile)return;try{$this->documentFileName=(string)$this->documentFile->getClientOriginalName();$this->documentFileMime=strtolower((string)($this->documentFile->getMimeType()?:'application/octet-stream'));$this->documentFileExt=strtolower((string)($this->documentFile->getClientOriginalExtension()?:pathinfo($this->documentFileName,PATHINFO_EXTENSION)));$this->documentFileBytes=(int)$this->documentFile->getSize();if(!in_array($this->documentFileExt,self::EXTENSIONS,true))throw new \RuntimeException(__('Only PDF and common image files are allowed.'));$path=$this->documentFile->getRealPath();$this->documentHash=$path&&is_file($path)?hash_file('sha256',$path):sha1($this->documentFileName.'|'.$this->documentFileBytes);if(!$this->isPdf()){$this->clientPdfPageCount=1;$this->pageMode='all';$this->pageRange='';}}catch(\Throwable $e){$this->removeDocument();$this->addError('documentFile',$e->getMessage());}}
    public function updatedPageMode():void{if($this->pageMode==='all')$this->pageRange='';$this->dispatch('v2-ocr-preview-range',range:$this->pageRange,custom:$this->pageMode==='custom');}
    public function updatedPageRange():void{$this->dispatch('v2-ocr-preview-range',range:$this->pageRange,custom:$this->pageMode==='custom');}
    public function updatedClientPdfPageCount():void{$this->clientPdfPageCount=max(1,min(3888,(int)$this->clientPdfPageCount));}
    public function removeDocument():void{$this->documentFile=null;$this->documentFileName=$this->documentFileMime=$this->documentFileExt=$this->documentHash=null;$this->documentFileBytes=$this->clientPdfPageCount=null;$this->pageMode='all';$this->pageRange='';$this->dispatch('v2-ocr-document-cleared');}
    public function resetOcr():void{$this->removeDocument();$this->runLlmCorrector=$this->exportDocx=$this->exportTxt=true;$this->exportMarkdown=$this->exportHtml=$this->exportZip=false;}
    public function isPdf():bool{return $this->documentFileExt==='pdf'||$this->documentFileMime==='application/pdf';}
    private function selectedPages():array{if($this->pageMode==='all'||!$this->isPdf())return [];$max=max(1,(int)($this->clientPdfPageCount?:3888));$pages=[];$range=preg_replace('/\s*-\s*/','-',trim($this->pageRange))??'';foreach(preg_split('/\s*,\s*/',$range)?:[] as $part){if(preg_match('/^(\d+)-(\d+)$/',$part,$m)&&(int)$m[1]<=(int)$m[2])foreach(range((int)$m[1],(int)$m[2])as$p)$pages[]=$p;elseif(preg_match('/^\d+$/',$part))$pages[]=(int)$part;else throw new \RuntimeException(__('Enter pages like 1-5,8,10-12.'));}$pages=array_values(array_unique(array_filter($pages,fn($p)=>$p>0&&$p<=$max)));sort($pages);if(!$pages)throw new \RuntimeException(__('Choose at least one valid page.'));return $pages;}
    private function estimatedPages():int{try{return $this->pageMode==='custom'?count($this->selectedPages()):max(1,(int)($this->isPdf()?($this->clientPdfPageCount?:1):1));}catch(\Throwable){return 0;}}
    #[Computed] public function creditsCost():int{$customer=auth('app')->user();return $customer&&$this->documentFile?max(0,(int)$customer->priceCreditsFor('ocr.standard',['metric_code'=>'page','pages'=>$this->estimatedPages(),'page_count'=>$this->estimatedPages(),'files'=>1])):0;}
    #[Computed] public function currentJob():?MlJob{return $this->currentJobId?MlJob::query()->with('tool')->whereKey($this->currentJobId)->where('customer_id',auth('app')->id())->first():null;}
    #[Computed] public function presentation():array{return app(MetKurdV2JobStatusPresentation::class)->for((string)($this->currentJob?->status??'idle'));}
    #[Computed] public function jobStage():string{return match((string)($this->currentJob?->status??'')){'queued'=>__('Queued — waiting for an OCR worker.'),'running'=>__('Scanning pages and extracting text.'),'saving'=>__('Saving your OCR result and downloads.'),'done'=>__('OCR scan completed.'),'failed'=>__('OCR scan failed.'),default=>__('Preparing OCR job.')};}
    #[Computed] public function currentText():string{return (string)data_get($this->currentJob?->output,'text.inline',data_get($this->currentJob?->output,'runpod.text',''));}
    #[Computed] public function recentJobs(){return MlJob::query()->where('customer_id',auth('app')->id())->where('job_kind','ocr')->where('input->v2',true)->when(trim($this->search)!=='',fn($q)=>$q->where('input->file_name','like','%'.trim($this->search).'%'))->latest('updated_at')->paginate(5,pageName:'ocrV2Page');}
    public function availableDownloads(?MlJob $job):array{if(!$job||$job->status!=='done')return [];$labels=['txt'=>'TXT','docx'=>'DOCX','markdown'=>'Markdown','html'=>'HTML','zip'=>'ZIP'];$downloads=[];foreach($labels as $format=>$label){$selected=(bool)data_get($job->input,"exports.export_{$format}",false);$path=$format==='txt'?data_get($job->output,'text.path'):data_get($job->output,"artifacts.{$format}.path");if($selected&&$path)$downloads[$format]=$label;}return $downloads;}
    public function downloadUrl(MlJob $job,string $format):string{return $format==='txt'?route('app.renders.ocr.text',['locale'=>app()->getLocale(),'jobId'=>$job->id]):route('app.v2.ocr.artifact',['locale'=>app()->getLocale(),'jobId'=>$job->id,'format'=>$format]);}
    public function submitOcr(OcrV2SubmissionService $submission):void{$this->submissionError='';$this->validate();if($this->currentJob?->isActive()){$this->submissionError=__('An OCR job is already in progress.');return;}try{$this->selectedPages();$job=$submission->submit(auth('app')->user(),$this->documentFile,['pages'=>$this->pageMode==='all'?'all':trim($this->pageRange),'estimated_pages'=>$this->estimatedPages(),'run_llm_corrector'=>$this->runLlmCorrector,'export_docx'=>$this->exportDocx,'export_txt'=>$this->exportTxt,'export_markdown'=>$this->exportMarkdown,'export_html'=>$this->exportHtml,'export_zip'=>$this->exportZip,'input_hash'=>(string)$this->documentHash,'file_name'=>(string)$this->documentFileName,'file_mime'=>(string)$this->documentFileMime,'file_ext'=>(string)$this->documentFileExt,'file_bytes'=>(int)$this->documentFileBytes]);$this->currentJobId=(string)$job->id;$this->showJobStatus=true;$this->dispatch('header:refresh');}catch(\Throwable $e){Log::warning('OCR_V2_WORKSPACE_SUBMIT_FAIL',['message'=>$e->getMessage()]);$this->submissionError=$e->getMessage();}}
    public function pollOcr(OcrJobSyncService $sync):void{if($job=$this->currentJob){$result=$sync->sync($job);$this->showJobStatus=in_array((string)data_get($result,'status'),['queued','running','saving'],true);$this->dispatch('header:refresh');}}
    public function copyText():void{$this->dispatch('v2-ocr-copy-text',text:$this->currentText);}
    public function cancelOcr(JobExecutionLockService $locks,CustomerOutputStorage $storage):void{$job=$this->currentJob;if(!$job||!$job->isActive())return;$job->update(['status'=>'failed','error'=>['message'=>__('Cancelled by customer.')],'finished_at'=>now()]);$this->showJobStatus=false;try{$storage->deleteFromDiskAndUncount((int)$job->customer_id,(string)data_get($job->input,'file_disk','s3'),(string)data_get($job->input,'file_path',''),(int)$job->storage_in_bytes);}catch(\Throwable){}$locks->releaseLock((string)$job->id);$this->dispatch('header:refresh');}
    private function hydrateCurrentJob():void{$job=MlJob::query()->where('customer_id',auth('app')->id())->where('job_kind','ocr')->where('input->v2',true)->whereIn('status',['queued','running','saving','failed','done'])->latest('updated_at')->first();$this->currentJobId=$job?(string)$job->id:null;$this->showJobStatus=(bool)$job?->isActive();}
}; ?>

<section class="v2-tool-page v2-ocr-page"><nav class="v2-breadcrumb"><a wire:navigate href="{{ route('app.v2.home',['locale'=>app()->getLocale()]) }}">{{ __('MetKurd AI') }}</a><span>/</span><a wire:navigate href="{{ route('app.v2.service',['locale'=>app()->getLocale(),'service'=>'ocr']) }}">{{ __('OCR') }}</a><span>/</span><span>{{ __('OCR Scanner 2.0') }}</span></nav><header class="v2-tool-context"><div class="v2-tool-identity d-flex align-items-center gap-3"><img class="v2-service-icon" src="{{ asset('app/services_icons/OCR.png') }}" alt=""><div><span>{{ __('OCR') }}</span><h1>{{ __('OCR Scanner 2.0') }}</h1></div></div>@livewire('app::v2.components.shared.account-resources')</header><div class="v2-workspace v2-ocr-workspace">
<main class="v2-workspace-panel v2-create-panel v2-ocr-upload-panel">
    <div class="v2-panel-heading"><span>{{ __('Document Upload') }}</span><small>{{ __('PDF and image OCR with visual preview') }}</small></div>
    <div class="v2-ocr-upload-copy"><i class="ri-file-search-line"></i><span>{{ __('Upload a PDF or image up to 200 MB. Preview pages before scanning.') }}</span></div>
    <div class="v2-ocr-dropzone" id="v2-ocr-dropzone" wire:ignore>
        <input id="v2-ocr-file" type="file" accept=".pdf,.png,.jpg,.jpeg,.webp,.bmp,.gif,.tif,.tiff,application/pdf,image/*">
        <label for="v2-ocr-file"><i class="ri-upload-cloud-2-line"></i><strong>{{ __('Choose document') }}</strong><small>{{ __('or drop it here') }}</small></label>
    </div>
    @if($documentFileName)<div class="v2-ocr-file"><i class="ri-file-text-line"></i><span><strong>{{ $documentFileName }}</strong><small>{{ number_format(($documentFileBytes??0)/1048576,1) }} MB · {{ $this->isPdf()?__('PDF document'):__('Image') }}</small></span><button wire:click="removeDocument" class="btn btn-sm btn-outline-info">{{ __('Remove') }}</button></div>@endif
    @error('documentFile')<small class="text-danger d-block mt-2">{{ $message }}</small>@enderror
    <div id="v2-ocr-preview" class="v2-ocr-preview" wire:ignore data-thumbnails-label="{{ __('PDF thumbnails') }}" data-invalid-range-label="{{ __('Enter a valid page range.') }}" data-choose-pages-label="{{ __('Choose pages to preview.') }}" data-upload-preview-label="{{ __('Upload a document to preview.') }}" data-image-preview-label="{{ __('Image preview') }}">
        <div class="v2-ocr-preview-toolbar"><button id="v2-ocr-prev" type="button" class="btn btn-sm btn-outline-info">{{ __('Prev') }}</button><button id="v2-ocr-next" type="button" class="btn btn-sm btn-outline-info">{{ __('Next') }}</button><span id="v2-ocr-page-info">{{ __('Upload a document to preview.') }}</span><button id="v2-ocr-expand" type="button" class="btn btn-sm btn-outline-info" title="{{ __('Open fullscreen preview') }}"><i class="ri-fullscreen-line"></i></button><label>{{ __('Zoom') }} <input id="v2-ocr-zoom" type="range" min="60" max="180" value="100"></label></div>
        <div class="v2-ocr-preview-grid"><aside id="v2-ocr-thumbs"><small>{{ __('PDF thumbnails') }}</small></aside><button id="v2-ocr-stage" type="button" title="{{ __('Open fullscreen preview') }}"><div class="v2-empty-state">{{ __('Your PDF or image preview will appear here.') }}</div><canvas id="v2-ocr-canvas"></canvas><img id="v2-ocr-image" alt=""></button></div>
    </div>
    <div id="v2-ocr-modal" class="v2-ocr-modal" aria-hidden="true" wire:ignore><div class="v2-ocr-modal-content"><button id="v2-ocr-modal-close" type="button" class="btn btn-sm btn-outline-light v2-ocr-modal-close" title="{{ __('Close preview') }}"><i class="ri-close-line"></i></button><div id="v2-ocr-modal-stage"><canvas id="v2-ocr-modal-canvas"></canvas><img id="v2-ocr-modal-image" alt=""></div></div></div>
</main>
<aside class="v2-workspace-panel v2-ocr-options-panel"><div class="v2-panel-heading"><span>{{ __('OCR Options') }}</span><button wire:click="resetOcr" class="btn btn-sm btn-outline-info">{{ __('Reset') }}</button></div><div class="v2-ocr-option-group"><strong>{{ __('Pages') }}</strong><div class="v2-segmented"><button type="button" wire:click="$set('pageMode','all')" @class(['is-active'=>$pageMode==='all'])>{{ __('All pages') }}</button><button type="button" wire:click="$set('pageMode','custom')" @class(['is-active'=>$pageMode==='custom'])>{{ __('Custom range') }}</button></div>@if($pageMode==='custom')<input wire:model.live="pageRange" class="form-control v2-control mt-2" placeholder="1-5,8,10-12"><small>{{ __('Use commas and ranges.') }}</small>@endif</div><label class="v2-ocr-intelligent"><span><strong>{{ __('Intelligent') }} <em>{{ __('Beta') }}</em></strong><small>{{ __('Improve extracted text with AI correction.') }}</small></span><span class="v2-asr-switch"><input type="checkbox" wire:model="runLlmCorrector" role="switch"><i></i></span></label><div class="v2-ocr-option-group"><strong>{{ __('Output Formats') }}</strong><div class="v2-ocr-format-grid"><label><input type="checkbox" wire:model="exportDocx"> DOCX</label><label><input type="checkbox" wire:model="exportMarkdown"> Markdown</label><label><input type="checkbox" wire:model="exportHtml"> HTML</label><label><input type="checkbox" wire:model="exportZip"> ZIP</label></div></div><div class="v2-editor-footer mt-3"><span>{{ __('Estimated cost') }} · {{ $documentFile?$this->estimatedPages():0 }} {{ __('pages') }}</span><span>{{ number_format($this->creditsCost) }} {{ __('credits') }}</span></div>@if($submissionError)<div class="v2-ocr-failed">{{ $submissionError }}</div>@endif @php($presentation=$this->presentation)<div class="v2-create-actions"><button wire:click="submitOcr" wire:loading.attr="disabled" wire:target="submitOcr,documentFile" @disabled(!$documentFile||$presentation['is_active']) class="btn btn-info px-4"><span wire:loading.remove wire:target="submitOcr,documentFile">{{ __('Scan Document') }}</span><span wire:loading wire:target="submitOcr,documentFile">{{ __('Preparing…') }}</span></button>@if($currentJobId)<span class="v2-job-state glass-load {{ $presentation['glass_class'] }}" @if($presentation['is_active']) wire:poll.1500ms="pollOcr" @endif>{{ $presentation['label'] }}</span>@endif</div>@if($presentation['is_active'])<button wire:click="cancelOcr" wire:confirm="{{ __('Cancel this OCR job?') }}" class="btn btn-sm btn-outline-danger mt-2">{{ __('Cancel job') }}</button>@endif</aside>
<aside class="v2-workspace-panel v2-ocr-result-panel">
    <div class="v2-panel-heading"><span>{{ __('Extracted Text') }}</span><small>{{ __('Latest OCR result') }}</small></div>
    @if($showJobStatus && $currentJobId)
        <div class="v2-ocr-job-status" wire:poll.1500ms="pollOcr">
            <span class="spinner-border spinner-border-sm"></span>
            <span><strong>{{ $presentation['label'] }}</strong><small>{{ $this->jobStage }}</small></span>
        </div>
    @endif
    @if($this->currentJob&&$presentation['is_active'])
        <div class="v2-ocr-processing"><span class="spinner-border spinner-border-sm"></span>{{ __('Scanning your document…') }}</div>
    @elseif($this->currentJob&&$this->currentJob->status==='failed')
        <div class="v2-ocr-failed">{{ data_get($this->currentJob->error,'message',__('OCR could not be completed.')) }}</div>
    @elseif($this->currentText)
        <div class="v2-ocr-text" dir="auto">{{ $this->currentText }}</div>
        <div class="d-flex flex-wrap gap-2 mt-2">
            <button wire:click="copyText" class="btn btn-sm btn-outline-info">{{ __('Copy') }}</button>
            @foreach($this->availableDownloads($this->currentJob) as $format=>$label)
                <a href="{{ $this->downloadUrl($this->currentJob,$format) }}" class="btn btn-sm btn-outline-info">{{ $label }}</a>
            @endforeach
        </div>
    @else
        <div class="v2-empty-state v2-ocr-empty">{{ __('Your corrected OCR text and available downloads will appear here.') }}</div>
    @endif
    <div class="v2-ocr-history">
        <div class="v2-panel-heading"><span>{{ __('Recent OCR Jobs') }}</span><small>{{ __('OCR Scanner 2.0') }}</small></div>
        @forelse($this->recentJobs as $job)
            <article class="v2-render-item is-{{ $job->status }}">
                <div class="d-flex justify-content-between gap-2"><strong>{{ data_get($job->input,'file_name',__('Document')) }}</strong><span class="v2-render-status">{{ __(ucfirst($job->status)) }}</span></div>
                <p>{{ data_get($job->input,'page_range')==='all'?__('All pages'):data_get($job->input,'page_range') }}</p>
                <small class="v2-muted">{{ optional($job->updated_at)->diffForHumans() }}</small>
                @if($downloads=$this->availableDownloads($job))
                    <div class="v2-ocr-job-downloads">
                        @foreach($downloads as $format=>$label)
                            <a href="{{ $this->downloadUrl($job,$format) }}" class="btn btn-sm btn-outline-info">{{ $label }}</a>
                        @endforeach
                    </div>
                @endif
            </article>
        @empty
            <div class="v2-empty-state">{{ __('Your recent OCR jobs will appear here.') }}</div>
        @endforelse
    </div>
</aside></div></section>
@push('styles')<style>.metkurd-v2 .v2-ocr-workspace{grid-template-columns:minmax(280px,.75fr) minmax(280px,.72fr) minmax(340px,1.15fr);border-color:rgba(var(--v2-accent-rgb),.4);background:linear-gradient(135deg,rgba(var(--v2-accent-rgb),.14),rgba(3,18,13,.3))}.metkurd-v2 .v2-ocr-workspace .v2-panel-heading>span{color:var(--v2-accent-text)}.metkurd-v2 .v2-ocr-upload-copy,.metkurd-v2 .v2-ocr-file,.metkurd-v2 .v2-ocr-processing,.metkurd-v2 .v2-ocr-failed{display:flex;gap:.65rem;align-items:center;margin:1rem 0;padding:.75rem;border:1px solid rgba(var(--v2-accent-rgb),.22);border-radius:.8rem;background:rgba(var(--v2-accent-rgb),.07);font-size:.78rem}.metkurd-v2 .v2-ocr-upload-copy i,.metkurd-v2 .v2-ocr-file>i{font-size:1.2rem;color:var(--v2-accent-text)}.metkurd-v2 .v2-ocr-file span{display:grid;min-width:0;flex:1}.metkurd-v2 .v2-ocr-file small,.metkurd-v2 .v2-ocr-intelligent small{color:rgba(226,232,240,.55)}.metkurd-v2 .v2-ocr-dropzone{margin-top:1rem;border:1px dashed rgba(var(--v2-accent-rgb),.55);border-radius:.9rem;background:linear-gradient(135deg,rgba(var(--v2-accent-rgb),.12),rgba(2,6,23,.42))}.metkurd-v2 .v2-ocr-dropzone input{position:absolute;inline-size:1px;block-size:1px;opacity:0}.metkurd-v2 .v2-ocr-dropzone label{display:grid;place-items:center;gap:.32rem;min-height:7rem;cursor:pointer;color:var(--v2-accent-text);text-align:center}.metkurd-v2 .v2-ocr-dropzone i{font-size:1.8rem}.metkurd-v2 .v2-ocr-dropzone small{color:rgba(226,232,240,.55)}.metkurd-v2 .v2-ocr-preview{margin-top:1rem;border:1px solid rgba(var(--v2-accent-rgb),.22);border-radius:.9rem;overflow:hidden;background:rgba(2,6,23,.42)}.metkurd-v2 .v2-ocr-preview-toolbar{display:flex;align-items:center;gap:.45rem;flex-wrap:wrap;padding:.55rem;border-bottom:1px solid rgba(148,163,184,.12);font-size:.68rem;color:rgba(226,232,240,.65)}.metkurd-v2 .v2-ocr-preview-toolbar label{margin-left:auto;display:flex;align-items:center;gap:.35rem}.metkurd-v2 .v2-ocr-preview-grid{display:grid;grid-template-columns:92px minmax(0,1fr);height:340px}.metkurd-v2 #v2-ocr-thumbs{overflow:auto;padding:.4rem;border-inline-end:1px solid rgba(148,163,184,.12)}.metkurd-v2 #v2-ocr-thumbs button{display:block;width:100%;margin:.35rem 0;padding:.15rem;border:1px solid transparent;border-radius:.35rem;background:transparent;color:inherit}.metkurd-v2 #v2-ocr-thumbs button.is-active{border-color:rgba(var(--v2-accent-rgb),.8);background:rgba(var(--v2-accent-rgb),.14)}.metkurd-v2 #v2-ocr-thumbs canvas{max-width:100%;height:auto}.metkurd-v2 #v2-ocr-stage{position:relative;display:grid;place-items:center;overflow:auto;padding:.75rem}.metkurd-v2 #v2-ocr-canvas,.metkurd-v2 #v2-ocr-image{display:none;max-width:100%;height:auto}.metkurd-v2 .v2-ocr-option-group{display:grid;gap:.65rem;margin-top:1rem;padding:.75rem;border:1px solid rgba(148,163,184,.14);border-radius:.8rem;background:rgba(15,23,42,.4);font-size:.78rem}.metkurd-v2 .v2-segmented{display:grid;grid-template-columns:1fr 1fr;border:1px solid rgba(148,163,184,.2);border-radius:.6rem;overflow:hidden}.metkurd-v2 .v2-segmented button{border:0;background:transparent;color:rgba(226,232,240,.65);padding:.45rem;font-size:.72rem}.metkurd-v2 .v2-segmented button.is-active{background:rgba(var(--v2-accent-rgb),.2);color:var(--v2-accent-text)}.metkurd-v2 .v2-ocr-intelligent{display:flex;justify-content:space-between;gap:1rem;align-items:center;min-height:4.35rem;margin-top:1rem;padding:.75rem;border:1px solid rgba(var(--v2-accent-rgb),.32);border-radius:.75rem;background:linear-gradient(135deg,rgba(var(--v2-accent-rgb),.1),rgba(2,6,23,.36));cursor:pointer}.metkurd-v2 .v2-ocr-intelligent>span:first-child{display:grid;gap:.14rem}.metkurd-v2 .v2-ocr-intelligent em{padding:.12rem .34rem;border-radius:999px;background:rgba(245,158,11,.16);color:#fde68a;font-size:.6rem;font-style:normal;text-transform:uppercase}.metkurd-v2 .v2-asr-switch{position:relative;display:block;flex:0 0 2.65rem;width:2.65rem;height:1.45rem}.metkurd-v2 .v2-asr-switch input{position:absolute;inset:0;z-index:1;width:100%;height:100%;opacity:0;cursor:pointer}.metkurd-v2 .v2-asr-switch i{position:absolute;inset:0;border:1px solid rgba(148,163,184,.45);border-radius:999px;background:rgba(15,23,42,.9)}.metkurd-v2 .v2-asr-switch i:after{position:absolute;top:3px;left:3px;width:calc(1.45rem - 8px);height:calc(1.45rem - 8px);border-radius:50%;background:#94a3b8;content:"";transition:transform .16s ease}.metkurd-v2 .v2-asr-switch input:checked+i{border-color:rgba(var(--v2-accent-rgb),.9);background:rgba(var(--v2-accent-rgb),.7)}.metkurd-v2 .v2-asr-switch input:checked+i:after{transform:translateX(1.18rem);background:#f0fdf4}.metkurd-v2 .v2-ocr-format-grid{display:grid;grid-template-columns:1fr 1fr;gap:.45rem}.metkurd-v2 .v2-ocr-format-grid label{padding:.45rem;border:1px solid rgba(148,163,184,.14);border-radius:.5rem;cursor:pointer}.metkurd-v2 .v2-ocr-format-grid input{accent-color:rgb(var(--v2-accent-rgb))}.metkurd-v2 .v2-ocr-result-panel{min-height:575px}.metkurd-v2 .v2-ocr-text{height:23rem;overflow:auto;margin-top:1rem;padding:1rem;border:1px solid rgba(var(--v2-accent-rgb),.32);border-radius:.9rem;background:linear-gradient(135deg,rgba(2,6,23,.7),rgba(var(--v2-accent-rgb),.05));white-space:pre-wrap;line-height:1.9;user-select:text}.metkurd-v2 .v2-ocr-processing{color:var(--v2-accent-text)}.metkurd-v2 .v2-ocr-failed{color:#fecaca;border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.08)}.metkurd-v2 .v2-ocr-history{display:grid;gap:.65rem;margin-top:1.25rem;padding-top:1rem;border-top:1px solid rgba(148,163,184,.12)}@media(max-width:1199.98px){.metkurd-v2 .v2-ocr-workspace{grid-template-columns:minmax(280px,.8fr) minmax(0,1.2fr)}.metkurd-v2 .v2-ocr-result-panel{grid-column:1/-1}}@media(max-width:767.98px){.metkurd-v2 .v2-ocr-workspace{grid-template-columns:1fr}.metkurd-v2 .v2-ocr-result-panel{grid-column:auto;min-height:0}.metkurd-v2 .v2-ocr-preview-grid{height:280px}.metkurd-v2 .v2-ocr-text{height:18rem}}</style>@endpush
@push('styles')
<style>
.metkurd-v2 .v2-ocr-dropzone.is-dragging{border-color:rgb(var(--v2-accent-rgb));box-shadow:0 0 0 .22rem rgba(var(--v2-accent-rgb),.14);background:rgba(var(--v2-accent-rgb),.18)}
.metkurd-v2 #v2-ocr-stage{min-width:0;border:0;background:transparent;color:inherit;cursor:zoom-in}
.metkurd-v2 .v2-ocr-job-status{display:flex;align-items:center;gap:.65rem;margin-top:1rem;padding:.8rem;border:1px solid rgba(var(--v2-accent-rgb),.35);border-radius:.8rem;background:rgba(var(--v2-accent-rgb),.08);color:var(--v2-accent-text)}
.metkurd-v2 .v2-ocr-job-status span:last-child{display:grid;gap:.12rem}.metkurd-v2 .v2-ocr-job-status small{color:rgba(226,232,240,.65)}
.metkurd-v2 .v2-ocr-job-downloads{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.65rem}
.metkurd-v2 .v2-ocr-modal{position:fixed;z-index:1080;inset:0;display:none;align-items:center;justify-content:center;padding:1.25rem;background:rgba(2,6,23,.88);backdrop-filter:blur(5px)}
.metkurd-v2 .v2-ocr-modal.is-open{display:flex}.metkurd-v2 .v2-ocr-modal-content{position:relative;display:grid;place-items:center;width:min(100%,1200px);height:min(100%,900px);padding:2.75rem 1rem 1rem;border:1px solid rgba(var(--v2-accent-rgb),.45);border-radius:1rem;background:#07111d;box-shadow:0 1.5rem 5rem rgba(0,0,0,.45)}
.metkurd-v2 .v2-ocr-modal-close{position:absolute;top:.8rem;right:.8rem;z-index:1}.metkurd-v2 #v2-ocr-modal-stage{width:100%;height:100%;display:grid;place-items:center;overflow:auto}.metkurd-v2 #v2-ocr-modal-canvas,.metkurd-v2 #v2-ocr-modal-image{display:none;max-width:100%;max-height:100%;object-fit:contain}
</style>
@endpush

@push('scripts')
<script type="module">
import * as pdfjs from 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.mjs';
pdfjs.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.worker.min.mjs';

(() => {
    let pdf = null, page = 1, scale = 1, url = null, imageUrl = null, visiblePages = [];
    const $ = id => document.getElementById(id);
    const preview = $('v2-ocr-preview');
    const labels = {
        thumbnails: preview?.dataset.thumbnailsLabel || 'PDF thumbnails',
        invalidRange: preview?.dataset.invalidRangeLabel || 'Enter a valid page range.',
        choosePages: preview?.dataset.choosePagesLabel || 'Choose pages to preview.',
        uploadPreview: preview?.dataset.uploadPreviewLabel || 'Upload a document to preview.',
        imagePreview: preview?.dataset.imagePreviewLabel || 'Image preview',
    };
    const root = () => document.querySelector('.v2-ocr-page')?.closest('[wire\\:id]');
    const livewire = () => root() ? window.Livewire?.find(root().getAttribute('wire:id')) : null;
    const isPdf = file => file && (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf'));
    const allowed = file => file && (/\.(pdf|png|jpe?g|webp|bmp|gif|tiff?)$/i.test(file.name) || isPdf(file));

    const pageRange = (range, maximum) => {
        const pages = new Set();
        const normalized = String(range || '').trim().replace(/\s*-\s*/g, '-');
        if (!normalized) return [];
        for (const part of normalized.split(/\s*,\s*/)) {
            const match = part.match(/^(\d+)(?:-(\d+))?$/);
            if (!match) return [];
            const start = Number(match[1]), end = Number(match[2] || match[1]);
            if (start < 1 || end < start || end > maximum) return [];
            for (let value = start; value <= end; value++) pages.add(value);
        }
        return [...pages].sort((a, b) => a - b);
    };

    const selectedPreviewPages = () => {
        if (!pdf) return [];
        const custom = livewire()?.get('pageMode') === 'custom';
        if (!custom) return Array.from({ length: Math.min(pdf.numPages, 24) }, (_, index) => index + 1);
        return pageRange(livewire()?.get('pageRange'), pdf.numPages).slice(0, 48);
    };

    const render = async (pageNumber, canvas, renderScale) => {
        const sourcePage = await pdf.getPage(pageNumber);
        const viewport = sourcePage.getViewport({ scale: renderScale });
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        await sourcePage.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
    };

    const draw = async pageNumber => {
        if (!pdf || !visiblePages.length) return;
        page = visiblePages.includes(pageNumber) ? pageNumber : visiblePages[0];
        const canvas = $('v2-ocr-canvas');
        await render(page, canvas, scale);
        canvas.style.display = 'block';
        $('v2-ocr-page-info').textContent = `${page} / ${pdf.numPages}`;
        document.querySelectorAll('#v2-ocr-thumbs button').forEach(button => button.classList.toggle('is-active', Number(button.dataset.page) === page));
    };

    const drawThumbnails = async () => {
        const holder = $('v2-ocr-thumbs');
        holder.innerHTML = `<small>${labels.thumbnails}</small>`;
        visiblePages = selectedPreviewPages();
        if (!visiblePages.length) {
            holder.insertAdjacentHTML('beforeend', `<small class="text-warning d-block mt-2">${labels.invalidRange}</small>`);
            $('v2-ocr-page-info').textContent = labels.choosePages;
            $('v2-ocr-canvas').style.display = 'none';
            return;
        }
        for (const pageNumber of visiblePages) {
            const thumbnail = document.createElement('canvas');
            await render(pageNumber, thumbnail, .16);
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.page = String(pageNumber);
            button.append(thumbnail);
            button.addEventListener('click', () => draw(pageNumber));
            holder.append(button);
        }
        await draw(visiblePages.includes(page) ? page : visiblePages[0]);
    };

    const refreshRange = () => pdf ? drawThumbnails() : undefined;
    const closePreview = () => {
        $('v2-ocr-modal')?.classList.remove('is-open');
        $('v2-ocr-modal')?.setAttribute('aria-hidden', 'true');
    };
    const openPreview = async () => {
        if (!pdf && !imageUrl) return;
        const modal = $('v2-ocr-modal');
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        if (pdf) {
            const canvas = $('v2-ocr-modal-canvas');
            $('v2-ocr-modal-image').style.display = 'none';
            await render(page, canvas, Math.min(2.2, Math.max(1.2, scale * 1.35)));
            canvas.style.display = 'block';
        } else {
            $('v2-ocr-modal-canvas').style.display = 'none';
            $('v2-ocr-modal-image').src = imageUrl;
            $('v2-ocr-modal-image').style.display = 'block';
        }
    };

    const clear = () => {
        pdf = null; page = 1; visiblePages = [];
        if (url) URL.revokeObjectURL(url);
        url = null; imageUrl = null;
        $('v2-ocr-thumbs').innerHTML = `<small>${labels.thumbnails}</small>`;
        $('v2-ocr-canvas').style.display = 'none';
        $('v2-ocr-image').style.display = 'none';
        $('v2-ocr-page-info').textContent = labels.uploadPreview;
        closePreview();
    };

    const load = async file => {
        clear();
        if (!allowed(file)) return;
        url = URL.createObjectURL(file);
        if (isPdf(file)) {
            pdf = await pdfjs.getDocument(url).promise;
            livewire()?.set('clientPdfPageCount', pdf.numPages);
            await drawThumbnails();
        } else {
            imageUrl = url;
            $('v2-ocr-image').src = url;
            $('v2-ocr-image').style.display = 'block';
            $('v2-ocr-page-info').textContent = labels.imagePreview;
        }
        livewire()?.upload('documentFile', file);
    };

    const fileInput = $('v2-ocr-file');
    fileInput?.addEventListener('change', event => load(event.target.files?.[0]));
    const dropzone = $('v2-ocr-dropzone');
    ['dragenter', 'dragover'].forEach(name => dropzone?.addEventListener(name, event => { event.preventDefault(); dropzone.classList.add('is-dragging'); }));
    ['dragleave', 'drop'].forEach(name => dropzone?.addEventListener(name, event => { event.preventDefault(); dropzone.classList.remove('is-dragging'); }));
    dropzone?.addEventListener('drop', event => load(event.dataTransfer?.files?.[0]));
    $('v2-ocr-prev')?.addEventListener('click', () => draw(visiblePages[Math.max(0, visiblePages.indexOf(page) - 1)]));
    $('v2-ocr-next')?.addEventListener('click', () => draw(visiblePages[Math.min(visiblePages.length - 1, visiblePages.indexOf(page) + 1)]));
    $('v2-ocr-zoom')?.addEventListener('input', event => { scale = Number(event.target.value) / 100; draw(page); });
    $('v2-ocr-stage')?.addEventListener('click', openPreview);
    $('v2-ocr-expand')?.addEventListener('click', openPreview);
    $('v2-ocr-modal-close')?.addEventListener('click', closePreview);
    $('v2-ocr-modal')?.addEventListener('click', event => { if (event.target === $('v2-ocr-modal')) closePreview(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') closePreview(); });
    window.Livewire?.on('v2-ocr-document-cleared', clear);
    window.Livewire?.on('v2-ocr-preview-range', refreshRange);
    window.Livewire?.on('v2-ocr-copy-text', event => navigator.clipboard?.writeText(event.text || ''));
    document.addEventListener('livewire:navigating', clear);
})();
</script>
@endpush

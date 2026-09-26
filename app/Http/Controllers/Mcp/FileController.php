<?php

namespace App\Http\Controllers\Mcp;

use App\Models\CustomerMcpConnection;
use App\Services\Mcp\Files;
use App\Services\Mcp\McpConnectionPrincipal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;

class FileController
{
    public function download(Request $request, string $id, TransportController $transport)
    {
        return $transport->authenticated($request, function ($principal) use ($id) {
            try {
                $file = app(Files::class)->result($principal, $id);
                $stream = Storage::disk($file->disk)->readStream($file->path);
                if (! is_resource($stream)) {
                    return new Response(404);
                }

                return new Response(200, ['Content-Type' => $file->mime, 'Content-Disposition' => 'attachment; filename="result.'.preg_replace('/[^a-z0-9]/i', '', pathinfo($file->path, PATHINFO_EXTENSION)).'"',
                    'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], Stream::create($stream));
            } catch (\Throwable) {
                return new Response(404, ['Content-Type' => 'application/json'], '{"error":"invalid_file"}');
            }
        }, streamed: true);
    }

    public function upload(Request $request, string $locale, string $id)
    {
        $customer = $request->user('app');
        $session = DB::table('mcp_upload_sessions')->where('customer_id', $customer->id)->where('id', $id)->first();
        abort_unless($session && now()->lessThan($session->expires_at), 404);
        $connection = CustomerMcpConnection::where('customer_id', $customer->id)->findOrFail($session->connection_id);
        $principal = new McpConnectionPrincipal($connection->id, $connection->scopes);
        $principal->authorize($customer, 'mcp:uploads');
        app(Files::class)->authorizePurpose($principal, $session->purpose);
        $fileId = $session->customer_file_id;
        if ($request->isMethod('post')) {
            $request->validate(['file' => 'required|file|max:102400']);
            $fileId = app(Files::class)->upload($principal, $id, $request->file('file'))->id;
        }

        return response()->view('app.v2.mcp.upload', compact('session', 'fileId'));
    }
}

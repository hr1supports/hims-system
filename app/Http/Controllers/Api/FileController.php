<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileExchange;
use App\Support\ExchangeFiles;
use Illuminate\Http\Request;

/**
 * File Exchange for HR2 / HR3 / HR4.
 * GET  /api/v1/files            files HIMS sent you (waiting for pickup; ?status=all for every file)
 * GET  /api/v1/files/{id}       download one (HIMS marks it "Picked up")
 * POST /api/v1/files            send a file to HIMS (multipart: file, subject, note, sender, reference)
 */
class FileController extends Controller
{
    public function index(Request $request)
    {
        $partner = $request->attributes->get('partner');
        $query = FileExchange::query()->from('file_exchange as f')
            ->leftJoin('users as u', 'u.id', '=', 'f.sent_by')
            ->where('f.direction', 'outgoing')->where('f.partner', $partner)->where('f.archived', 0)
            ->orderByDesc('f.id')->limit(200);
        if ($request->query('status') !== 'all') {
            $query->where('f.status', 'Waiting');
        }
        $records = $query->get([
            'f.id', 'f.subject', 'f.note', 'f.original_name as fileName', 'f.mime_type as mimeType',
            'f.file_size as size', 'f.sha256', 'f.status', 'u.name as sentBy', 'f.created_at as sentAt',
        ])->map(fn ($r) => $r->toArray() + ['downloadUrl' => url('/api/v1/files/'.$r->id)]);

        return response()->json(['partner' => $partner, 'records' => $records]);
    }

    public function download(Request $request, int $id)
    {
        $file = FileExchange::query()->where('id', $id)->where('direction', 'outgoing')
            ->where('partner', $request->attributes->get('partner'))->where('archived', 0)->first();
        $path = $file ? ExchangeFiles::path((string) $file->stored_name) : null;
        if (! $path) {
            return response()->json(['error' => 'File not found.'], 404);
        }
        FileExchange::query()->where('id', $file->id)->update([
            'status' => 'Picked up',
            'picked_up_at' => $file->picked_up_at ?? now(),
        ]);

        return response()->download($path, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function store(Request $request)
    {
        $partner = $request->attributes->get('partner');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('hims.exchange_max_kb')],
            'subject' => ['required', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:2000'],
            'sender' => ['nullable', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:60'],
        ]);
        $recent = FileExchange::query()->where('direction', 'incoming')->where('partner', $partner)
            ->where('created_at', '>', now()->subHour())->count();
        if ($recent >= 100) {
            return response()->json(['error' => 'Too many files sent in the last hour. Try again later.'], 429);
        }

        $saved = ExchangeFiles::store($request->file('file'));
        $file = FileExchange::query()->create($saved + [
            'direction' => 'incoming',
            'partner' => $partner,
            'subject' => trim($data['subject']),
            'note' => $data['note'] ?? null,
            'partner_sender' => $data['sender'] ?? null,
            'partner_ref' => $data['reference'] ?? null,
            'status' => 'New',
        ]);

        return response()->json(['id' => $file->id, 'sha256' => $saved['sha256'], 'message' => "File received from {$partner}."], 201);
    }
}

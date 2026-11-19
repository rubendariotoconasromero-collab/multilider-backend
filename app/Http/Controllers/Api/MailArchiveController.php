<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MailArchive;
use App\Services\MailDocumentContent;
use Illuminate\Http\Request;

class MailArchiveController extends Controller
{
    public function index(Request $request, MailArchive $archive)
    {
        $data = $request->validate(['search' => 'nullable|string|max:200', 'modulo' => 'nullable|in:reporte,campana',
            'desde' => 'nullable|date_format:Y-m-d', 'hasta' => 'nullable|date_format:Y-m-d'.($request->filled('desde') ? '|after_or_equal:desde' : ''),
            'estado' => 'nullable|in:pendiente,incierto,aceptado_smtp,fallido', 'campana_id' => 'nullable|integer|min:1',
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50']);
        $query = $archive->visible($request)->with('documents')->orderByDesc('created_at')->orderBy('id');
        if (!empty($data['search'])) $query->where('asunto', 'like', '%'.addcslashes($data['search'], '%_\\').'%');
        if (!empty($data['modulo'])) $query->where('modulo', $data['modulo']);
        if (!empty($data['desde'])) $query->whereDate('created_at', '>=', $data['desde']);
        if (!empty($data['hasta'])) $query->whereDate('created_at', '<=', $data['hasta']);
        if (!empty($data['campana_id'])) $query->where('campana_id', $data['campana_id']);
        // State derives from existing campaign recipients and report deliveries.
        if (!empty($data['estado'])) $archive->filterState($query, $data['estado']);
        $page = $query->paginate($data['per_page'] ?? 10);
        $page->setCollection($page->getCollection()->map(fn ($row) => $archive->describe($row)));
        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, MailArchive $archive, string $id)
    {
        $data = $request->validate(['recipient_page' => 'sometimes|integer|min:1|max:100000']);
        $dispatch = $archive->visible($request)->with('documents')->find($id);
        abort_unless($dispatch, 404, 'El envío no está disponible para esta cuenta.');
        return response()->json($archive->describe($dispatch, true, (int)($data['recipient_page'] ?? 1)))->header('Cache-Control', 'private, no-store');
    }

    public function content(Request $request, MailArchive $archive, MailDocumentContent $content, string $id, string $file)
    {
        $data = $request->validate(['inline' => 'sometimes|boolean', 'preview' => 'sometimes|boolean',
            'page' => 'sometimes|integer|min:1|max:10000', 'sheet' => 'sometimes|integer|min:0|max:49']);
        $dispatch = $archive->visible($request)->find($id);
        abort_unless($dispatch, 404, 'El envío no está disponible para esta cuenta.');
        $document = $dispatch->documents()->find($file);
        abort_unless($document, 404, 'El archivo no está asociado a este envío.');
        $path = $archive->file($document);
        if ($request->boolean('preview')) return response()->json($content->preview($path, $document->nombre, (int)($data['page'] ?? 1), (int)($data['sheet'] ?? 0)))->header('Cache-Control', 'private, no-store');
        return $content->response($path, $document->nombre, $request->boolean('inline'));
    }
}

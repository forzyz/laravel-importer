<?php
namespace App\Http\Controllers;

use App\Http\Requests\UploadImportRequest;
use App\Jobs\ImportCatalog;
use Barryvdh\Debugbar\Facades\Debugbar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImportController extends Controller
{
    public function index()
    {
        Log::info('GET /import');
        return view('import');
    }

    public function store(UploadImportRequest $req)
    {
        /** @var \Illuminate\Http\UploadedFile|null $uploaded */
        $uploaded = $req->file('file');
        Log::info('POST /import', [
            'filename' => $uploaded?->getClientOriginalName(),
            'size'     => $uploaded?->getSize(),
            'mime'     => $uploaded?->getMimeType(),
        ]);
        $path = $uploaded->store('imports');

        $token = (string) Str::uuid();
        Cache::put("import:$token", ['total' => 0, 'imported' => 0, 'skipped' => 0, 'reasons' => []], 3600);

        ImportCatalog::dispatch($path, $token)->onQueue('imports');

        return redirect()->route('import.index')->with('token', $token);
    }

    public function progress(string $token, Request $req)
    {
        Log::info('GET /import/progress', ['token' => $token, 'since' => $req->query('since')]);
        if (class_exists(Debugbar::class)) {
            Debugbar::disable();
        }

        $since = (int) $req->query('since', 0);
        $key   = "import:$token";

        $start = microtime(true);
        while (microtime(true) - $start < 25) {
            $state = Cache::get($key);
            if (! $state) {
                return response()->json(['total' => 0, 'imported' => 0, 'skipped' => 0, 'reasons' => [], 'ver' => $since, 'done' => true]);
            }
            $ver = (int) $state['ver'] ?? 0;
            if ($ver > $since || $state['done'] ?? false) {
                return response()->json($state + ['ver' => $ver]);
            }
            usleep(250_000);
        }

        $state = Cache::get($key) ?? [
            'total'    => 0,
            'imported' => 0,
            'skipped'  => 0,
            'reasons'  => [],
            'done'     => false,
            'ver'      => $since,
        ];

        return response()->json(Cache::get($key));
    }
}

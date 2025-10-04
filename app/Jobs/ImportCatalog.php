<?php
namespace App\Jobs;

use App\Models\Category;
use App\Models\Product;
use App\Support\Import\RowParsing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Facades\Excel;

class ImportCatalog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $path, public string $token)
    {
    }

    public function handle(): void
    {
        // Increase memory limit for heavy Excel imports; configurable via env
        ini_set('memory_limit', (string) env('IMPORT_MEMORY_LIMIT', '512M'));
        $key = "import:{$this->token}";
        Cache::put($key, Cache::get($key) ?? ['total' => 0, 'imported' => 0, 'skipped' => 0, 'reasons' => [], 'ver' => 0, 'done' => false], 3600);

        $headers = null;
        $map     = null;

        try {
            Excel::import(new class($key) implements WithMultipleSheets
            {
                public function __construct(public $key)
                {
                }

                public function sheets(): array
                {

                    // process every sheet the same way
                    return [new class($this->key) implements \Maatwebsite\Excel\Concerns\OnEachRow, \Maatwebsite\Excel\Concerns\WithChunkReading
                    {
                        public function __construct(public $key)
                        {
                        }
                        public function chunkSize(): int
                        {
                            return 200;
                        }

                        private ?array $last = ['cat1' => null, 'cat2' => null, 'cat3' => null, 'brand' => null];

                        private ?array $headers             = null;
                        private ?array $map                 = null;
                        private array $categoryCache        = [];
                        private array $pendingCategoryNames = [];
                        private array $productBuffer        = [];
                        private int $bufferLimit            = 200;

                        public function onRow(\Maatwebsite\Excel\Row $row)
                        {
                            $cells = array_map(fn($v) => is_string($v) ? trim($v) : (is_numeric($v) ? (string) $v : ($v ?? '')), $row->toArray());

                            // Skip completely empty rows
                            if (! array_filter($cells, fn($v) => null !== $v && '' !== $v)) {
                                return;
                            }

                            for ($i = 0; $i < 3; $i++) {
                                if (($cells[$i] ?? '') === '' && null !== $this->last["cat" . ($i + 1)]) {
                                    $cells[$i] = $this->last["cat" . ($i + 1)];
                                }
                            }
                            if (($cells[3] ?? '') === '' && null !== $this->last['brand']) {
                                $cells[3] = $this->last['brand'];
                            }

                            $this->last['cat1']  = $cells[0] ?? $this->last['cat1'];
                            $this->last['cat2']  = $cells[1] ?? $this->last['cat2'];
                            $this->last['cat3']  = $cells[2] ?? $this->last['cat3'];
                            $this->last['brand'] = $cells[3] ?? $this->last['brand'];

                            // If current row looks like a header, remember it and skip this row only.
                            if (null === $this->headers && (RowParsing::looksLikeHeader($cells) || RowParsing::couldBeHeader($cells))) {
                                $this->headers = array_map(
                                    fn($h) => strtolower(preg_replace('/\s+/', ' ', (string) $h)),
                                    $cells
                                );
                                // Optional best-effort map for diagnostics; data processing does not depend on it.
                                $this->map = RowParsing::buildMap($this->headers);
                                return;
                            }

                            $this->bump('total');

                            $sku   = RowParsing::pickSku($cells);
                            $name  = RowParsing::pickName($cells, $sku, $this->last);
                            $price = RowParsing::pickPrice($cells);

                            $category = implode(' / ', array_filter([$cells[0] ?? '', $cells[1] ?? '', $cells[2] ?? ''], fn($s) => '' !== $s));
                            if ('' === $category && $this->last['cat1']) {
                                $category = implode(' / ', array_filter([$this->last['cat1'], $this->last['cat2'], $this->last['cat3']], fn($s) => $s));
                            }

                            // if ($name === '') {
                            //     // fallback: try brand, else SKU
                            //     $name = ($this->last['brand'] ?? '') !== '' ? (string)$this->last['brand'] : $sku;
                            // }
                            if ('' === $sku || '' === $category) {
                                $this->reason('invalid', 'Missing sku/name/category');
                                return;
                            }

                            // Clamp to schema limits
                            $category = RowParsing::clamp($category, 512);
                            $sku      = RowParsing::clamp($sku, 64);
                            $name     = RowParsing::clamp($name, 512);

                            if (! isset($this->categoryCache[$category])) {
                                $this->pendingCategoryNames[$category] = true;
                            }

                            $this->productBuffer[] = [
                                'sku'           => $sku,
                                'name'          => $name,
                                'category_name' => $category,
                                'price'         => $price,
                            ];

                            if (count($this->productBuffer) >= $this->bufferLimit) {
                                $this->flushProducts();
                            }
                        }

                        public function __destruct()
                        {
                            if (! empty($this->productBuffer)) {
                                $this->flushProducts();
                            }
                        }

                        private function bump(string $field, int $by = 1): void
                        {
                            Cache::increment("{$this->key}:$field", $by);
                            $state             = Cache::get($this->key) ?? [];
                            $state['total']    = (int) Cache::get("{$this->key}:total");
                            $state['imported'] = (int) Cache::get("{$this->key}:imported");
                            $state['skipped']  = (int) Cache::get("{$this->key}:skipped");
                            $state['reasons']  = $state['reasons'] ?? [];
                            $state['ver']      = (int) ($state['ver'] ?? 0) + 1;
                            Cache::put($this->key, $state, 3600);
                        }
                        private function bumpBy(string $field, int $by): void
                        {
                            if ($by <= 0) {
                                return;
                            }

                            $this->bump($field, $by);
                        }
                        private function reason($key, $msg): void
                        {
                            Cache::increment("{$this->key}:skipped");
                            $state            = Cache::get($this->key) ?? [];
                            $reasons          = $state['reasons'] ?? [];
                            $reasons[$key]    = ($reasons[$key] ?? 0) + 1;
                            $state['reasons'] = $reasons;
                            $state['ver']     = (int) ($state['ver'] ?? 0) + 1;
                            Cache::put($this->key, $state, 3600);
                        }
                        private function reasonCount($key, int $count): void
                        {
                            if ($count <= 0) {
                                return;
                            }

                            Cache::increment("{$this->key}:skipped", $count);
                            $state            = Cache::get($this->key) ?? [];
                            $reasons          = $state['reasons'] ?? [];
                            $reasons[$key]    = ($reasons[$key] ?? 0) + $count;
                            $state['reasons'] = $reasons;
                            $state['ver']     = (int) ($state['ver'] ?? 0) + 1;
                            Cache::put($this->key, $state, 3600);
                        }
                        private function flushCategories(): void
                        {
                            if (empty($this->pendingCategoryNames)) {
                                return;
                            }

                            $names   = array_keys($this->pendingCategoryNames);
                            $now     = now();
                            $inserts = [];
                            foreach ($names as $n) {
                                $inserts[] = [
                                    'name'       => $n,
                                    'slug'       => RowParsing::normalizeSlug($n),
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ];
                            }
                            Category::insertOrIgnore($inserts);
                            $ids = Category::whereIn('name', $names)->pluck('id', 'name')->all();
                            foreach ($ids as $name => $id) {
                                $this->categoryCache[$name] = (int) $id;
                            }
                            $this->pendingCategoryNames = [];
                        }
                        private function flushProducts(): void
                        {
                            $this->flushCategories();
                            if (empty($this->productBuffer)) {
                                return;
                            }

                            $rows = [];
                            $now  = now();
                            foreach ($this->productBuffer as $p) {
                                $catId = $this->categoryCache[$p['category_name']] ?? null;
                                if (! $catId) {
                                    Category::insertOrIgnore([
                                        'name'       => $p['category_name'],
                                        'slug'       => RowParsing::normalizeSlug($p['category_name']),
                                        'created_at' => $now,
                                        'updated_at' => $now,
                                    ]);
                                    $catId = (int) (Category::where('name', $p['category_name'])->value('id'));
                                    if ($catId) {
                                        $this->categoryCache[$p['category_name']] = $catId;
                                    }

                                }
                                if ($catId) {
                                    $rows[] = [
                                        'sku'         => $p['sku'],
                                        'name'        => $p['name'],
                                        'category_id' => $catId,
                                        'price'       => $p['price'],
                                        'created_at'  => $now,
                                        'updated_at'  => $now,
                                    ];
                                } else {
                                    $this->reason('invalid', 'Category resolution failed');
                                }
                            }

                            if (! empty($rows)) {
                                $inserted = (int) Product::insertOrIgnore($rows);
                                $this->bumpBy('imported', $inserted);
                                $duplicates = count($rows) - $inserted;
                                $this->reasonCount('duplicate', $duplicates);
                            }
                            $this->productBuffer = [];
                            // Encourage freeing memory between batches
                            if (function_exists('gc_collect_cycles')) {
                                gc_collect_cycles();
                            }
                        }

                    }];
                }
            }, Storage::disk('local')->path($this->path));
        } catch (\Exception $e) {
            $state          = Cache::get($key) ?? [];
            $state['error'] = 'Import failed: ' . $e->getMessage();
            $state['done']  = true;
            $state['ver']   = (int) ($state['ver'] ?? 0) + 1;
            Cache::put($key, $state, 3600);
            throw $e;
        } finally {
            $state                = Cache::get($key) ?? [];
            $state['done']        = true;
            $state['finished_at'] = now()->toAtomString();
            $state['ver']         = (int) ($state['ver'] ?? 0) + 1;
            Cache::put($key, $state, 3600);
        }
    }
}

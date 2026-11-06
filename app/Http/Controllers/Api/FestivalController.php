<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FestivalResource;
use App\Models\AdminNotificationModel;
use App\Models\FestivalModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FestivalController extends Controller
{
    public function index(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'month' => ['nullable', 'date_format:Y-m'],
            'category' => ['nullable', 'in:budaya,pariwisata,seni'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Parameter tidak valid', 'errors' => $validator->errors()], 422);
        }

        // ?all=1 (dipakai dashboard admin) menampilkan juga event nonaktif.
        $query = $request->boolean('all') ? FestivalModel::query() : FestivalModel::query()->active();

        if ($request->boolean('upcoming')) {
            $query->upcoming();
        }

        if ($request->filled('month')) {
            $query->inMonth($request->query('month'));
        }

        if ($request->filled('category')) {
            $query->category($request->query('category'));
        }

        $festivals = $query->orderBy('event_date')->orderBy('id')->paginate($this->perPage($request));

        return response()->json([
            'data' => FestivalResource::collection($festivals->items()),
            'meta' => [
                'current_page' => $festivals->currentPage(),
                'last_page' => $festivals->lastPage(),
                'per_page' => $festivals->perPage(),
                'total' => $festivals->total(),
            ],
        ], 200);
    }

    public function show(int $id)
    {
        $festival = FestivalModel::query()->active()->find($id);

        if (!$festival) {
            return response()->json(['message' => 'Festival tidak ditemukan'], 404);
        }

        return response()->json(['data' => new FestivalResource($festival)], 200);
    }

    // --- Mutasi khusus admin (route-nya di-guard ['auth:sanctum', 'admin']) ---

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return [
            'name' => "{$req}|string|max:255",
            'location_type' => 'nullable|string|max:50',
            'location_name' => "{$req}|string|max:255",
            'category' => 'nullable|in:budaya,pariwisata,seni',
            'address' => 'nullable|string|max:255',
            'event_date' => "{$req}|date",
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'description' => 'nullable|string',
            'image_url' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return response()->json(['message' => 'Data event tidak valid', 'errors' => $validator->errors()], 422);
        }

        $festival = FestivalModel::create($validator->validated() + ['location_type' => 'panggung', 'is_active' => true]);

        AdminNotificationModel::notify('festival', 'Event Festival Baru Ditambahkan', "{$festival->name} dijadwalkan {$festival->event_date->toDateString()} di {$festival->location_name}.");

        return response()->json(['message' => 'Event berhasil dibuat', 'data' => new FestivalResource($festival)], 201);
    }

    public function update(Request $request, int $id)
    {
        $festival = FestivalModel::findOrFail($id);
        $validator = Validator::make($request->all(), $this->rules(partial: true));

        if ($validator->fails()) {
            return response()->json(['message' => 'Data event tidak valid', 'errors' => $validator->errors()], 422);
        }

        $festival->update($validator->validated());

        return response()->json(['message' => 'Event berhasil diperbarui', 'data' => new FestivalResource($festival)]);
    }

    public function destroy(int $id)
    {
        FestivalModel::findOrFail($id)->delete();

        return response()->json(['message' => 'Event berhasil dihapus']);
    }
}

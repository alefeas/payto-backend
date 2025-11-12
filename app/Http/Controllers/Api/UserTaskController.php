<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserTask;
use Illuminate\Http\Request;

class UserTaskController extends Controller
{
    public function index(Request $request)
    {
        $tasks = UserTask::where('user_id', auth()->id())
            ->orderBy('is_completed', 'asc')
            ->orderBy('due_date', 'asc')
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($tasks);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string',
            'priority' => 'required|in:low,medium,high',
            'due_date' => 'required|date',
        ], [
            'title.required' => 'El título es requerido',
            'title.max' => 'El título no puede exceder 200 caracteres',
            'priority.required' => 'La prioridad es requerida',
            'priority.in' => 'La prioridad debe ser baja, media o alta',
            'due_date.required' => 'La fecha de entrega es requerida',
            'due_date.date' => 'La fecha de entrega debe ser una fecha válida',
        ]);

        $task = UserTask::create([
            'user_id' => auth()->id(),
            ...$validated,
        ]);

        return response()->json($task, 201);
    }

    public function update(Request $request, $id)
    {
        $task = UserTask::where('user_id', auth()->id())->findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:200',
            'description' => 'nullable|string',
            'priority' => 'sometimes|in:low,medium,high',
            'is_completed' => 'sometimes|boolean',
            'due_date' => 'nullable|date',
        ]);

        if (isset($validated['is_completed']) && $validated['is_completed'] && !$task->is_completed) {
            $validated['completed_at'] = now();
        } elseif (isset($validated['is_completed']) && !$validated['is_completed']) {
            $validated['completed_at'] = null;
        }

        $task->update($validated);

        return response()->json($task);
    }

    public function destroy($id)
    {
        $task = UserTask::where('user_id', auth()->id())->findOrFail($id);
        $task->delete();

        return response()->json(['message' => 'Tarea eliminada']);
    }
}

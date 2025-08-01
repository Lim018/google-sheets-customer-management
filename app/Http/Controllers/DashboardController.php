<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        
        if ($user->role === 'admin') {
            return $this->adminDashboard($request);
        }
        
        return $this->agentDashboard($request);
    }
    
    private function agentDashboard(Request $request)
    {
        $user = Auth::user();
        $query = Customer::where('user_id', $user->id)->active(); // Only show active customers
        
        // Filter berdasarkan pencarian nama
        if ($request->filled('search')) {
            $query->where('nama', 'like', '%' . $request->search . '%');
        }
        
        // Filter berdasarkan status
        if ($request->filled('status')) {
            $statusGroups = [
                'normal' => ['normal', 'normal(prospect)'],
                'warm' => ['warm', 'warm(potential)'],
                'hot' => ['hot', 'hot(closeable)']
            ];
            
            if (isset($statusGroups[$request->status])) {
                $query->whereIn('status_fu', $statusGroups[$request->status]);
            }
        }
        
        // Filter berdasarkan bulan sheet
        if ($request->filled('month')) {
            $query->where('sheet_month', $request->month);
        }
        
        // Filter berdasarkan status follow up
        if ($request->filled('followup_status')) {
            if ($request->followup_status === 'pending') {
                $query->whereNotNull('followup_date')
                      ->where('followup_date', '>=', Carbon::today());
            } elseif ($request->followup_status === 'overdue') {
                $query->whereNotNull('followup_date')
                      ->where('followup_date', '<', Carbon::today());
            } elseif ($request->followup_status === 'completed') {
                $query->where('fu_checkbox', true);
            }
        }
        
        $customers = $query->orderBy('created_at', 'desc')->paginate(15);
        
        // Statistics untuk dashboard
        $stats = [
            'total_customers' => Customer::where('user_id', $user->id)->active()->count(),
            'normal_status' => Customer::where('user_id', $user->id)->active()
                ->whereIn('status_fu', ['normal', 'normal(prospect)'])->count(),
            'warm_status' => Customer::where('user_id', $user->id)->active()
                ->whereIn('status_fu', ['warm', 'warm(potential)'])->count(),
            'hot_status' => Customer::where('user_id', $user->id)->active()
                ->whereIn('status_fu', ['hot', 'hot(closeable)'])->count(),
            'followup_today' => Customer::where('user_id', $user->id)->active()
                ->whereDate('followup_date', Carbon::today())->count(),
            'overdue_followup' => Customer::where('user_id', $user->id)->active()
                ->where('followup_date', '<', Carbon::today())->count(),
            'archived_count' => Customer::where('user_id', $user->id)->archived()->count()
        ];
        
        // Available months untuk filter
        $availableMonths = Customer::where('user_id', $user->id)->active()
            ->whereNotNull('sheet_month')
            ->distinct()
            ->pluck('sheet_month')
            ->sort();
        
        return view('dashboard.agent', compact('customers', 'stats', 'availableMonths'));
    }
    
    private function adminDashboard(Request $request)
    {
        // Statistics untuk admin
        $stats = [
            'total_customers' => Customer::active()->count(),
            'total_agents' => \App\Models\User::where('role', 'agent')->count(),
            'normal_status' => Customer::active()->whereIn('status_fu', ['normal', 'normal(prospect)'])->count(),
            'warm_status' => Customer::active()->whereIn('status_fu', ['warm', 'warm(potential)'])->count(),
            'hot_status' => Customer::active()->whereIn('status_fu', ['hot', 'hot(closeable)'])->count(),
            'followup_today' => Customer::active()->whereDate('followup_date', Carbon::today())->count(),
            'closed_deals' => Customer::active()->whereNotNull('tanggal_closing')->count(),
            'archived_count' => Customer::archived()->count()
        ];
        
        // Data per agent
        $agentStats = \App\Models\User::where('role', 'agent')
            ->withCount([
                'customers' => function($query) {
                    $query->active();
                },
                'customers as normal_count' => function($query) {
                    $query->active()->whereIn('status_fu', ['normal', 'normal(prospect)']);
                },
                'customers as warm_count' => function($query) {
                    $query->active()->whereIn('status_fu', ['warm', 'warm(potential)']);
                },
                'customers as hot_count' => function($query) {
                    $query->active()->whereIn('status_fu', ['hot', 'hot(closeable)']);
                },
                'customers as closed_count' => function($query) {
                    $query->active()->whereNotNull('tanggal_closing');
                },
                'customers as archived_count' => function($query) {
                    $query->archived();
                }
            ])->get();
        
        // Chart data
        $chartData = [
            'status_distribution' => [
                'labels' => ['Normal', 'Warm', 'Hot'],
                'data' => [$stats['normal_status'], $stats['warm_status'], $stats['hot_status']]
            ],
            'agent_performance' => [
                'labels' => $agentStats->pluck('name')->toArray(),
                'data' => $agentStats->pluck('customers_count')->toArray()
            ]
        ];
        
        return view('dashboard.admin', compact('stats', 'agentStats', 'chartData'));
    }
    
    public function followupToday()
    {
        $user = Auth::user();
        
        $query = Customer::active()->whereDate('followup_date', Carbon::today());

        
        $stats = [
            'archived_count' => Customer::where('user_id', $user->id)->archived()->count()
        ];
        
        if ($user->role === 'agent') {
            $query->where('user_id', $user->id);
        }
        
        $customers = $query->orderBy('followup_date', 'asc')->get();
        
        return view('dashboard.followup-today', compact('customers', 'stats'));
    }

    public function archived(Request $request)
    {
        $user = Auth::user();
        
        $query = Customer::archived();
        
        if ($user->role === 'agent') {
            $query->where('user_id', $user->id);
        }
        
        // Filter berdasarkan pencarian nama
        if ($request->filled('search')) {
            $query->where('nama', 'like', '%' . $request->search . '%');
        }
        
        $customers = $query->with(['archivedBy'])
            ->orderBy('archived_at', 'desc')
            ->paginate(15);
        
        return view('dashboard.archived', compact('customers'));
    }
    
    public function updateCustomer(Request $request, Customer $customer)
    {
        $user = Auth::user();
        
        // Pastikan agent hanya bisa update customer miliknya
        if ($user->role === 'agent' && $customer->user_id !== $user->id) {
            abort(403);
        }
        
        $request->validate([
            'notes' => 'nullable|string',
            'followup_date' => 'nullable|date',
            'fu_checkbox' => 'boolean'
        ]);
        
        $oldData = $customer->toArray();
        
        $customer->update([
            'notes' => $request->notes,
            'followup_date' => $request->followup_date,
            'fu_checkbox' => $request->has('fu_checkbox')
        ]);
        
        // Log aktivitas
        ActivityLog::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'action' => 'updated',
            'description' => 'Customer data updated manually',
            'old_data' => $oldData,
            'new_data' => $customer->fresh()->toArray()
        ]);
        
        return back()->with('success', 'Customer updated successfully');
    }

    public function markCompleted(Customer $customer)
    {
        $user = Auth::user();
        
        // Pastikan agent hanya bisa update customer miliknya
        if ($user->role === 'agent' && $customer->user_id !== $user->id) {
            abort(403);
        }
        
        $oldData = $customer->toArray();
        
        $customer->update([
            'fu_checkbox' => true
        ]);
        
        // Log aktivitas
        ActivityLog::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'action' => 'status_changed',
            'description' => 'Marked follow-up as completed',
            'old_data' => $oldData,
            'new_data' => $customer->fresh()->toArray()
        ]);
        
        return back()->with('success', 'Follow-up marked as completed');
    }

    public function archiveCustomer(Customer $customer)
    {
        $user = Auth::user();
        
        // Pastikan agent hanya bisa archive customer miliknya
        if ($user->role === 'agent' && $customer->user_id !== $user->id) {
            abort(403);
        }
        
        $oldData = $customer->toArray();
        
        $customer->archive($user->id);
        
        // Log aktivitas
        ActivityLog::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'action' => 'archived',
            'description' => 'Customer moved to archive',
            'old_data' => $oldData,
            'new_data' => $customer->fresh()->toArray()
        ]);
        
        return back()->with('success', 'Customer has been archived');
    }

    public function restoreCustomer(Customer $customer)
    {
        $user = Auth::user();
        
        // Pastikan agent hanya bisa restore customer miliknya
        if ($user->role === 'agent' && $customer->user_id !== $user->id) {
            abort(403);
        }
        
        $oldData = $customer->toArray();
        
        $customer->restore();
        
        // Log aktivitas
        ActivityLog::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'action' => 'restored',
            'description' => 'Customer restored from archive',
            'old_data' => $oldData,
            'new_data' => $customer->fresh()->toArray()
        ]);
        
        return back()->with('success', 'Customer has been restored');
    }
}
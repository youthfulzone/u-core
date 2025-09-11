<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Laravel 12 Optimized Resource with conditional loading and caching
 */
class CompanyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cui' => $this->cui,
            'denumire' => $this->whenNotNull($this->denumire),
            'status' => $this->status,
            'type' => 'company',
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            // Conditional loading of heavy fields
            'synced_at' => $this->whenNotNull($this->synced_at?->toISOString()),
            'locked' => $this->when($this->locked !== null, $this->locked),
            
            // API source information
            'source_api' => $this->whenNotNull($this->source_api),
            
            // Tax information (only when needed)
            $this->mergeWhen($request->get('include_tax_data'), [
                'tax_category' => $this->whenNotNull($this->tax_category),
                'employees_current' => $this->whenNotNull($this->employees_current),
                'vat' => $this->when($this->vat !== null, $this->vat),
                'split_vat' => $this->when($this->split_vat !== null, $this->split_vat),
                'checkout_vat' => $this->when($this->checkout_vat !== null, $this->checkout_vat),
            ]),
            
            // Manual addition flag
            'manual_added' => $this->when($this->manual_added !== null, $this->manual_added),
        ];
    }
}
<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Placeholder;
use Illuminate\Contracts\View\View;

class PriceCalculator extends Placeholder
{
    protected string $view = 'filament.forms.components.price-calculator';
    
    public function getRenderableView(): View
    {
        return view($this->getView(), [
            'field' => $this,
            'initialDiscount' => $this->evaluate($this->discountPercentage),
            'initialVatRate' => $this->evaluate($this->vatRate),
            'initialCustomerRole' => $this->evaluate($this->customerRole),
        ]);
    }
    
    protected mixed $customerRole = null;
    protected mixed $discountPercentage = 0;
    protected mixed $vatRate = 21;
    
    public function customerRole($role): static
    {
        $this->customerRole = $role;
        
        return $this;
    }
    
    public function discountPercentage($discount): static
    {
        $this->discountPercentage = $discount;
        
        return $this;
    }
    
    public function vatRate($rate): static
    {
        $this->vatRate = $rate;
        
        return $this;
    }
    
    public function getCustomerRole(): ?string
    {
        return $this->evaluate($this->customerRole);
    }
    
    public function getDiscountPercentage(): float
    {
        return (float) $this->evaluate($this->discountPercentage);
    }
    
    public function getVatRate(): float
    {
        return (float) $this->evaluate($this->vatRate);
    }
}


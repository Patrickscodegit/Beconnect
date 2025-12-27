<?php

namespace App\Filament\Resources\CarrierRuleResource\Pages;

use App\Filament\Resources\CarrierRuleResource;
use App\Models\CarrierCategoryGroup;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCarrierRule extends EditRecord
{
    protected static string $resource = CarrierRuleResource::class;
    
    /**
     * Temporary storage for member_categories data (virtual field)
     * This is stored here to avoid database save attempts
     */
    protected ?array $memberCategoriesData = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Populate member_categories checkbox list from existing members when loading form
     * Also populate new checkbox fields from legacy single values for all rule types
     */
    protected function mutateFormDataUsing(array $data): array
    {
        // For each category group, populate member_categories from members relationship
        if (isset($data['categoryGroups']) && is_array($data['categoryGroups'])) {
            foreach ($data['categoryGroups'] as $key => $group) {
                if (isset($group['id'])) {
                    $groupModel = CarrierCategoryGroup::find($group['id']);
                    if ($groupModel) {
                        $memberCategories = $groupModel->members()->pluck('vehicle_category')->toArray();
                        $data['categoryGroups'][$key]['member_categories'] = $memberCategories;
                    }
                } else {
                    // New group, initialize empty array
                    $data['categoryGroups'][$key]['member_categories'] = [];
                }
            }
        }

        // For each rule type's repeater data, migrate single values to arrays if arrays are empty/null
        foreach (['acceptanceRules', 'transformRules', 'surchargeRules', 'classificationBands', 'surchargeArticleMaps'] as $ruleType) {
            if (isset($data[$ruleType]) && is_array($data[$ruleType])) {
                foreach ($data[$ruleType] as $key => $rule) {
                    // Migrate single values to arrays if arrays are empty/null
                    if (empty($rule['port_ids']) && !empty($rule['port_id'])) {
                        $data[$ruleType][$key]['port_ids'] = [$rule['port_id']];
                    }
                    if (empty($rule['vehicle_categories']) && !empty($rule['vehicle_category'])) {
                        $data[$ruleType][$key]['vehicle_categories'] = [$rule['vehicle_category']];
                    }
                    if (empty($rule['vessel_names']) && !empty($rule['vessel_name'])) {
                        $data[$ruleType][$key]['vessel_names'] = [$rule['vessel_name']];
                    }
                    if (empty($rule['vessel_classes']) && !empty($rule['vessel_class'])) {
                        $data[$ruleType][$key]['vessel_classes'] = [$rule['vessel_class']];
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Store member_categories data before save (it's a virtual field)
     * When using ->relationship() on Repeater, the data is saved separately.
     * We need to get it from the form component's state.
     */
    protected function beforeSave(): void
    {
        // Access the form component's state using reflection
        // The Repeater data with virtual fields is stored in the form component's state
        $categoryGroupsData = null;
        
        try {
            // Try to access form component's state property via reflection
            $formReflection = new \ReflectionClass($this->form);
            if ($formReflection->hasProperty('state')) {
                $stateProperty = $formReflection->getProperty('state');
                $stateProperty->setAccessible(true);
                $formState = $stateProperty->getValue($this->form);
                
                if (isset($formState['categoryGroups'])) {
                    $categoryGroupsData = $formState['categoryGroups'];
                }
            }
        } catch (\Exception $e) {
            // Reflection failed, fall through to data property access
        }
        
        // Fallback: Try accessing through component's data property
        if (!$categoryGroupsData && isset($this->data['categoryGroups'])) {
            $categoryGroupsData = $this->data['categoryGroups'];
        }

        // Store member_categories temporarily in the record for afterSave processing
        if ($categoryGroupsData && is_array($categoryGroupsData)) {
            $memberCategoriesData = [];
            
            foreach ($categoryGroupsData as $key => $group) {
                // Handle both array and object formats
                $groupArray = is_array($group) ? $group : (array) $group;

                if (isset($groupArray['member_categories'])) {
                    $memberCategoriesData[] = [
                        'id' => $groupArray['id'] ?? null,
                        'code' => $groupArray['code'] ?? null,
                        'member_categories' => $groupArray['member_categories'],
                    ];
                }
            }
            
            // Store in a temporary class property (not on the model to avoid DB save)
            $this->memberCategoriesData = $memberCategoriesData;
        }
    }

    /**
     * Sync member_categories checkbox selections to carrier_category_group_members table after save
     */
    protected function afterSave(): void
    {
        $record = $this->record;

        // Get the stored member_categories data from class property
        $memberCategoriesData = $this->memberCategoriesData;

        if ($memberCategoriesData && is_array($memberCategoriesData)) {
            // Refresh the record to get updated category groups
            $record->refresh();
            $categoryGroups = $record->categoryGroups;
            
            foreach ($memberCategoriesData as $groupData) {
                $selectedCategories = $groupData['member_categories'] ?? [];

                // Find the corresponding group by code (works for both new and existing groups)
                $groupModel = null;
                if (isset($groupData['id'])) {
                    $groupModel = $categoryGroups->find($groupData['id']);
                } elseif (isset($groupData['code'])) {
                    $groupModel = $categoryGroups->where('code', $groupData['code'])->first();
                }
                
                if ($groupModel) {
                    // Get current members
                    $currentMembers = $groupModel->members()->pluck('vehicle_category')->toArray();

                    // Delete members that are no longer selected
                    $toDelete = array_diff($currentMembers, $selectedCategories);
                    if (!empty($toDelete)) {
                        $groupModel->members()->whereIn('vehicle_category', $toDelete)->delete();
                    }
                    
                    // Create new members for newly selected categories
                    $toCreate = array_diff($selectedCategories, $currentMembers);
                    foreach ($toCreate as $category) {
                        $groupModel->members()->create([
                            'vehicle_category' => $category,
                            'is_active' => true,
                        ]);
                    }
                }
            }
            
            // Refresh the record and its relationships to ensure form shows updated data
            $record->refresh();
            $record->load('categoryGroups.members');
            
            // Update the form data with fresh member_categories so checkboxes show correctly
            // This ensures when Filament reloads the form, it has the latest data
            if (isset($this->data['categoryGroups']) && is_array($this->data['categoryGroups'])) {
                foreach ($this->data['categoryGroups'] as $key => $groupData) {
                    $groupId = $groupData['id'] ?? null;
                    if ($groupId) {
                        $groupModel = $record->categoryGroups->find($groupId);
                        if ($groupModel) {
                            // Update the form data with fresh member categories
                            $this->data['categoryGroups'][$key]['member_categories'] = 
                                $groupModel->members()->pluck('vehicle_category')->toArray();
                        }
                    }
                }
                
                // Update the form state with the fresh data
                try {
                    $this->form->fill($this->data);
                } catch (\Exception $e) {
                    // If fill fails, that's okay - Filament will reload from DB
                }
            }
            
            // Clear the temporary data
            $this->memberCategoriesData = null;
        }
    }
}


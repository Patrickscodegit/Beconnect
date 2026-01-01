<?php

namespace App\Filament\Resources\CarrierRuleResource\Pages;

use App\Filament\Resources\CarrierRuleResource;
use App\Models\CarrierCategoryGroup;
use App\Models\CarrierPortGroup;
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
    
    /**
     * Temporary storage for member_ports data (virtual field for port groups)
     */
    protected ?array $memberPortsData = null;
    
    /**
     * Temporary storage for category groups order (for sort_order updates)
     */
    protected ?array $categoryGroupsOrder = null;
    
    /**
     * Temporary storage for port groups order (for sort_order updates)
     */
    protected ?array $portGroupsOrder = null;
    
    /**
     * Temporary storage for acceptance rules order (for sort_order updates)
     */
    protected ?array $acceptanceRulesOrder = null;
    
    /**
     * Temporary storage for transform rules order (for sort_order updates)
     */
    protected ?array $transformRulesOrder = null;
    
    /**
     * Temporary storage for surcharge rules order (for sort_order updates)
     */
    protected ?array $surchargeRulesOrder = null;
    
    /**
     * Temporary storage for surcharge article maps order (for sort_order updates)
     */
    protected ?array $surchargeArticleMapsOrder = null;
    
    /**
     * Temporary storage for clauses order (for sort_order updates)
     */
    protected ?array $clausesOrder = null;
    
    /**
     * Temporary storage for article mappings order (for sort_order updates)
     */
    protected ?array $articleMappingsOrder = null;

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

        // For each port group, populate member_ports from members relationship
        if (isset($data['portGroups']) && is_array($data['portGroups'])) {
            foreach ($data['portGroups'] as $key => $group) {
                if (isset($group['id'])) {
                    $groupModel = CarrierPortGroup::find($group['id']);
                    if ($groupModel) {
                        $memberPorts = $groupModel->members()->pluck('port_id')->toArray();
                        $data['portGroups'][$key]['member_ports'] = $memberPorts;
                    }
                } else {
                    // New group, initialize empty array
                    $data['portGroups'][$key]['member_ports'] = [];
                }
            }
        }

        // For each rule type's repeater data, migrate single values to arrays if arrays are empty/null
        foreach (['acceptanceRules', 'transformRules', 'surchargeRules', 'surchargeArticleMaps'] as $ruleType) {
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
    /**
     * Get form state data for a given field
     * Always gets fresh state using reflection to ensure we have the latest order
     * This is called every time beforeSave() runs to get the current form state
     */
    protected function getFormStateData(string $fieldName): ?array
    {
        // Always get fresh state using reflection (don't cache, always read current state)
        try {
            $formReflection = new \ReflectionClass($this->form);
            if ($formReflection->hasProperty('state')) {
                $stateProperty = $formReflection->getProperty('state');
                $stateProperty->setAccessible(true);
                // Get fresh state value (not cached)
                $formState = $stateProperty->getValue($this->form);
                
                if (isset($formState[$fieldName]) && is_array($formState[$fieldName])) {
                    return $formState[$fieldName];
                }
            }
        } catch (\Exception $e) {
            // Reflection failed, try alternative approach
        }
        
        // Fallback: use data property (but this might be stale)
        if (isset($this->data[$fieldName]) && is_array($this->data[$fieldName])) {
            return $this->data[$fieldName];
        }
        
        return null;
    }

    protected function beforeSave(): void
    {
        // Get form state data using the helper method (always gets latest state)
        $categoryGroupsData = $this->getFormStateData('categoryGroups');
        $acceptanceRulesData = $this->getFormStateData('acceptanceRules');
        $transformRulesData = $this->getFormStateData('transformRules');
        $surchargeRulesData = $this->getFormStateData('surchargeRules');
        $surchargeArticleMapsData = $this->getFormStateData('surchargeArticleMaps');
        $clausesData = $this->getFormStateData('clauses');
        $articleMappingsData = $this->getFormStateData('articleMappings');
        $portGroupsData = $this->getFormStateData('portGroups');

        // Store member_categories temporarily in the record for afterSave processing
        if ($categoryGroupsData && is_array($categoryGroupsData)) {
            $memberCategoriesData = [];
            $categoryGroupsOrder = [];
            
            // Convert to numeric array to preserve order (Filament uses string keys like "record-1")
            $orderedGroups = array_values($categoryGroupsData);
            
            foreach ($orderedGroups as $index => $group) {
                // Handle both array and object formats
                $groupArray = is_array($group) ? $group : (array) $group;

                if (isset($groupArray['member_categories'])) {
                    $memberCategoriesData[] = [
                        'id' => $groupArray['id'] ?? null,
                        'code' => $groupArray['code'] ?? null,
                        'member_categories' => $groupArray['member_categories'],
                    ];
                }
                
                // Store order information (index + 1 = sort_order)
                // The order in the array reflects the visual order after reordering
                if (isset($groupArray['id'])) {
                    $categoryGroupsOrder[] = [
                        'id' => $groupArray['id'],
                        'sort_order' => $index + 1, // Use numeric index + 1 as sort_order
                    ];
                }
            }
            
            // Store in temporary class properties (not on the model to avoid DB save)
            $this->memberCategoriesData = $memberCategoriesData;
            $this->categoryGroupsOrder = $categoryGroupsOrder;
        }
        
        // Capture acceptance rules order
        if ($acceptanceRulesData && is_array($acceptanceRulesData)) {
            $acceptanceRulesOrder = [];
            $orderedRules = array_values($acceptanceRulesData);
            
            foreach ($orderedRules as $index => $rule) {
                $ruleArray = is_array($rule) ? $rule : (array) $rule;
                if (isset($ruleArray['id'])) {
                    $acceptanceRulesOrder[] = [
                        'id' => $ruleArray['id'],
                        'sort_order' => $index + 1,
                    ];
                }
            }
            
            $this->acceptanceRulesOrder = $acceptanceRulesOrder;
        }
        
        // Capture transform rules order
        if ($transformRulesData && is_array($transformRulesData)) {
            $transformRulesOrder = [];
            $orderedRules = array_values($transformRulesData);
            
            foreach ($orderedRules as $index => $rule) {
                $ruleArray = is_array($rule) ? $rule : (array) $rule;
                if (isset($ruleArray['id'])) {
                    $transformRulesOrder[] = [
                        'id' => $ruleArray['id'],
                        'sort_order' => $index + 1,
                    ];
                }
            }
            
            $this->transformRulesOrder = $transformRulesOrder;
        }
        
        // Capture surcharge rules order
        if ($surchargeRulesData && is_array($surchargeRulesData)) {
            $surchargeRulesOrder = [];
            $orderedRules = array_values($surchargeRulesData);
            
            foreach ($orderedRules as $index => $rule) {
                $ruleArray = is_array($rule) ? $rule : (array) $rule;
                if (isset($ruleArray['id'])) {
                    $surchargeRulesOrder[] = [
                        'id' => $ruleArray['id'],
                        'sort_order' => $index + 1,
                    ];
                }
            }
            
            $this->surchargeRulesOrder = $surchargeRulesOrder;
        }
        
        // Capture surcharge article maps order
        if ($surchargeArticleMapsData && is_array($surchargeArticleMapsData)) {
            $surchargeArticleMapsOrder = [];
            $orderedMaps = array_values($surchargeArticleMapsData);
            
            foreach ($orderedMaps as $index => $map) {
                $mapArray = is_array($map) ? $map : (array) $map;
                if (isset($mapArray['id'])) {
                    $surchargeArticleMapsOrder[] = [
                        'id' => $mapArray['id'],
                        'sort_order' => $index + 1,
                    ];
                }
            }
            
            $this->surchargeArticleMapsOrder = $surchargeArticleMapsOrder;
        }
        
        // Capture clauses order
        if ($clausesData && is_array($clausesData)) {
            $clausesOrder = [];
            $orderedClauses = array_values($clausesData);
            
            foreach ($orderedClauses as $index => $clause) {
                $clauseArray = is_array($clause) ? $clause : (array) $clause;
                if (isset($clauseArray['id'])) {
                    $clausesOrder[] = [
                        'id' => $clauseArray['id'],
                        'sort_order' => $index + 1,
                    ];
                }
            }
            
            $this->clausesOrder = $clausesOrder;
        }
        
        // Capture article mappings order
        if ($articleMappingsData && is_array($articleMappingsData)) {
            $articleMappingsOrder = [];
            $orderedMappings = array_values($articleMappingsData);
            
            foreach ($orderedMappings as $index => $mapping) {
                $mappingArray = is_array($mapping) ? $mapping : (array) $mapping;
                if (isset($mappingArray['id'])) {
                    $articleMappingsOrder[] = [
                        'id' => $mappingArray['id'],
                        'sort_order' => $index + 1,
                    ];
                }
            }
            
            $this->articleMappingsOrder = $articleMappingsOrder;
        }
        
        // Store member_ports temporarily for port groups
        if ($portGroupsData && is_array($portGroupsData)) {
            $memberPortsData = [];
            $portGroupsOrder = [];
            
            $orderedGroups = array_values($portGroupsData);
            
            foreach ($orderedGroups as $index => $group) {
                $groupArray = is_array($group) ? $group : (array) $group;

                if (isset($groupArray['member_ports'])) {
                    $memberPortsData[] = [
                        'id' => $groupArray['id'] ?? null,
                        'code' => $groupArray['code'] ?? null,
                        'member_ports' => $groupArray['member_ports'],
                    ];
                }
                
                if (isset($groupArray['id'])) {
                    $portGroupsOrder[] = [
                        'id' => $groupArray['id'],
                        'sort_order' => $index + 1,
                    ];
                }
            }
            
            $this->memberPortsData = $memberPortsData;
            $this->portGroupsOrder = $portGroupsOrder;
        }
    }

    /**
     * Sync member_categories checkbox selections to carrier_category_group_members table after save
     */
    protected function afterSave(): void
    {
        $record = $this->record;

        // Update sort_order based on form state order (if reordered)
        if ($this->categoryGroupsOrder && is_array($this->categoryGroupsOrder)) {
            foreach ($this->categoryGroupsOrder as $orderData) {
                $group = CarrierCategoryGroup::find($orderData['id']);
                if ($group && $group->sort_order != $orderData['sort_order']) {
                    $group->sort_order = $orderData['sort_order'];
                    $group->save();
                }
            }
        }
        
        // Update port groups sort_order based on form state order
        if ($this->portGroupsOrder && is_array($this->portGroupsOrder)) {
            foreach ($this->portGroupsOrder as $orderData) {
                $group = CarrierPortGroup::find($orderData['id']);
                if ($group && $group->sort_order != $orderData['sort_order']) {
                    $group->sort_order = $orderData['sort_order'];
                    $group->save();
                }
            }
        }
        
        // Update acceptance rules sort_order based on form state order
        if ($this->acceptanceRulesOrder && is_array($this->acceptanceRulesOrder)) {
            foreach ($this->acceptanceRulesOrder as $orderData) {
                $rule = \App\Models\CarrierAcceptanceRule::find($orderData['id']);
                if ($rule && $rule->sort_order != $orderData['sort_order']) {
                    $rule->sort_order = $orderData['sort_order'];
                    $rule->save();
                }
            }
        }
        
        // Update transform rules sort_order based on form state order
        if ($this->transformRulesOrder && is_array($this->transformRulesOrder)) {
            foreach ($this->transformRulesOrder as $orderData) {
                $rule = \App\Models\CarrierTransformRule::find($orderData['id']);
                if ($rule && $rule->sort_order != $orderData['sort_order']) {
                    $rule->sort_order = $orderData['sort_order'];
                    $rule->save();
                }
            }
        }
        
        // Update surcharge rules sort_order based on form state order
        if ($this->surchargeRulesOrder && is_array($this->surchargeRulesOrder)) {
            foreach ($this->surchargeRulesOrder as $orderData) {
                $rule = \App\Models\CarrierSurchargeRule::find($orderData['id']);
                if ($rule && $rule->sort_order != $orderData['sort_order']) {
                    $rule->sort_order = $orderData['sort_order'];
                    $rule->save();
                }
            }
        }
        
        // Update surcharge article maps sort_order based on form state order
        if ($this->surchargeArticleMapsOrder && is_array($this->surchargeArticleMapsOrder)) {
            foreach ($this->surchargeArticleMapsOrder as $orderData) {
                $map = \App\Models\CarrierSurchargeArticleMap::find($orderData['id']);
                if ($map && $map->sort_order != $orderData['sort_order']) {
                    $map->sort_order = $orderData['sort_order'];
                    $map->save();
                }
            }
        }
        
        // Update clauses sort_order based on form state order
        if ($this->clausesOrder && is_array($this->clausesOrder)) {
            foreach ($this->clausesOrder as $orderData) {
                $clause = \App\Models\CarrierClause::find($orderData['id']);
                if ($clause && $clause->sort_order != $orderData['sort_order']) {
                    $clause->sort_order = $orderData['sort_order'];
                    $clause->save();
                }
            }
        }
        
        // Update article mappings sort_order based on form state order
        if ($this->articleMappingsOrder && is_array($this->articleMappingsOrder)) {
            foreach ($this->articleMappingsOrder as $orderData) {
                $mapping = \App\Models\CarrierArticleMapping::find($orderData['id']);
                if ($mapping && $mapping->sort_order != $orderData['sort_order']) {
                    $mapping->sort_order = $orderData['sort_order'];
                    $mapping->save();
                }
            }
        }

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
        
        // Sync member_ports for port groups
        $memberPortsData = $this->memberPortsData;
        
        if ($memberPortsData && is_array($memberPortsData)) {
            $record->refresh();
            $portGroups = $record->portGroups;
            
            foreach ($memberPortsData as $groupData) {
                $selectedPorts = $groupData['member_ports'] ?? [];
                
                $groupModel = null;
                if (isset($groupData['id'])) {
                    $groupModel = $portGroups->find($groupData['id']);
                } elseif (isset($groupData['code'])) {
                    $groupModel = $portGroups->where('code', $groupData['code'])->first();
                }
                
                if ($groupModel) {
                    $currentMembers = $groupModel->members()->pluck('port_id')->toArray();
                    
                    $toDelete = array_diff($currentMembers, $selectedPorts);
                    if (!empty($toDelete)) {
                        $groupModel->members()->whereIn('port_id', $toDelete)->delete();
                    }
                    
                    $toCreate = array_diff($selectedPorts, $currentMembers);
                    foreach ($toCreate as $portId) {
                        $groupModel->members()->create([
                            'port_id' => $portId,
                            'is_active' => true,
                        ]);
                    }
                }
            }
            
            $record->refresh();
            $record->load('portGroups.members');
            
            if (isset($this->data['portGroups']) && is_array($this->data['portGroups'])) {
                foreach ($this->data['portGroups'] as $key => $groupData) {
                    $groupId = $groupData['id'] ?? null;
                    if ($groupId) {
                        $groupModel = $record->portGroups->find($groupId);
                        if ($groupModel) {
                            $this->data['portGroups'][$key]['member_ports'] = 
                                $groupModel->members()->pluck('port_id')->toArray();
                        }
                    }
                }
                
                try {
                    $this->form->fill($this->data);
                } catch (\Exception $e) {
                    // If fill fails, that's okay
                }
            }
            
            $this->memberPortsData = null;
        }
    }
}


<div class="space-y-6">
    <!-- Extraction Metadata -->
    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
        <div class="grid grid-cols-3 gap-4 text-sm">
            <div>
                <span class="font-medium text-gray-700 dark:text-gray-300">Confidence:</span>
                <span class="ml-2 text-gray-900 dark:text-gray-100">{{ number_format($extraction->confidence * 100, 1) }}%</span>
            </div>
            <div>
                <span class="font-medium text-gray-700 dark:text-gray-300">Extracted:</span>
                <span class="ml-2 text-gray-900 dark:text-gray-100">{{ $extraction->created_at->format('M j, Y g:i A') }}</span>
            </div>
            <div>
                <span class="font-medium text-gray-700 dark:text-gray-300">Status:</span>
                @if($extraction->verified_at)
                    <span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                        Verified
                    </span>
                @else
                    <span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                        Unverified
                    </span>
                @endif
            </div>
        </div>
    </div>

    <!-- Copy Button -->
    <div class="flex justify-between items-center mb-4">
        <h4 class="text-lg font-medium text-gray-900 dark:text-gray-100">Extracted Information</h4>
        <button onclick="
            const elem = document.getElementById('extractedText');
            const text = elem ? (elem.innerText || elem.textContent || '') : '';
            if (!text.trim()) {
                alert('No text available to copy.');
                return;
            }
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(() => {
                    this.innerHTML = '<svg class=\'w-4 h-4 mr-2\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M5 13l4 4L19 7\'></path></svg>Copied!';
                    this.className = this.className.replace('text-blue-600 bg-white hover:bg-blue-50 border-blue-600', 'text-green-600 bg-green-50 hover:bg-green-100 border-green-600');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                }).catch(() => {
                    alert('Failed to copy text. Please select and copy manually.');
                });
            } else {
                const textArea = document.createElement('textarea');
                textArea.value = text;
                textArea.style.position = 'fixed';
                textArea.style.left = '-9999px';
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    const successful = document.execCommand('copy');
                    if (successful) {
                        this.innerHTML = '<svg class=\'w-4 h-4 mr-2\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M5 13l4 4L19 7\'></path></svg>Copied!';
                        this.className = this.className.replace('text-blue-600 bg-white hover:bg-blue-50 border-blue-600', 'text-green-600 bg-green-50 hover:bg-green-100 border-green-600');
                        setTimeout(() => { location.reload(); }, 1500);
                    } else {
                        alert('Failed to copy text. Please select and copy manually.');
                    }
                } catch {
                    alert('Failed to copy text. Please select and copy manually.');
                }
                document.body.removeChild(textArea);
            }
        " id="copyButton"
                class="inline-flex items-center px-4 py-2 border-2 border-blue-600 text-sm font-medium rounded-lg text-blue-600 bg-white hover:bg-blue-50 hover:text-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200 shadow-sm">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
            </svg>
            Copy Extracted Info
        </button>
    </div>

    <!-- Extracted Data in Text Format -->
    <div class="space-y-4">        
        @php
            $data = $extraction->extracted_data ?? $extraction->raw_json;
            if (is_string($data)) {
                $data = json_decode($data, true);
            }
        @endphp

        @if($data && is_array($data))
            <div class="bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg p-4">
                <pre id="extractedText" class="whitespace-pre-wrap font-mono text-sm text-gray-900 dark:text-gray-100 leading-relaxed">@php
// Format the extracted data as readable text
$output = '';

// Helper function to format nested arrays
function formatValue($value, $indent = 0) {
    $spaces = str_repeat('  ', $indent);
    
    if (is_array($value)) {
        $result = '';
        foreach ($value as $key => $val) {
            if (is_numeric($key)) {
                $result .= $spaces . '• ' . formatValue($val, $indent) . "\n";
            } else {
                $label = ucwords(str_replace('_', ' ', $key));
                $result .= $spaces . $label . ': ' . formatValue($val, $indent + 1) . "\n";
            }
        }
        return rtrim($result);
    }
    
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }
    
    if (is_null($value)) {
        return 'N/A';
    }
    
    return (string) $value;
}

// Contact Information Section
if (isset($data['contact']) || isset($data['contact_info'])) {
    $contact = $data['contact'] ?? $data['contact_info'];
    $output .= "CONTACT INFORMATION\n";
    $output .= "==================\n";
    
    if (isset($contact['name'])) {
        $output .= "Name: " . $contact['name'] . "\n";
    }
    if (isset($contact['phone'])) {
        $output .= "Phone: " . $contact['phone'] . "\n";
    }
    if (isset($contact['email'])) {
        $output .= "Email: " . $contact['email'] . "\n";
    }
    $output .= "\n";
}

// Shipping Details Section
if (isset($data['shipment']) || isset($data['shipping'])) {
    $shipment = $data['shipment'] ?? $data['shipping'];
    $output .= "SHIPPING DETAILS\n";
    $output .= "================\n";
    
    if (isset($shipment['origin'])) {
        $output .= "Origin: " . $shipment['origin'] . "\n";
    }
    if (isset($shipment['destination'])) {
        $output .= "Destination: " . $shipment['destination'] . "\n";
    }
    if (isset($shipment['pickup_date'])) {
        $output .= "Pickup Date: " . $shipment['pickup_date'] . "\n";
    }
    if (isset($shipment['delivery_date'])) {
        $output .= "Delivery Date: " . $shipment['delivery_date'] . "\n";
    }
    $output .= "\n";
}

// Vehicle Information Section
$vehicle = null;
if (isset($data['vehicle'])) {
    $vehicle = $data['vehicle'];
} elseif (isset($data['vehicle_details'])) {
    $vehicle = $data['vehicle_details'];
} elseif (isset($data['vehicle_info'])) {
    $vehicle = $data['vehicle_info'];
} elseif (isset($data['vehicle_listing'])) {
    $vehicle = $data['vehicle_listing'];
} elseif (isset($data['shipment']['vehicle'])) {
    $vehicle = $data['shipment']['vehicle'];
}

if ($vehicle) {
    $output .= "VEHICLE INFORMATION\n";
    $output .= "===================\n";
    
    // Check for brand (new format from email extraction)
    if (!empty($vehicle['brand'])) {
        $output .= "Brand: " . $vehicle['brand'] . "\n";
    }
    
    // Check for full_name first (complete vehicle name)
    if (!empty($vehicle['full_name'])) {
        $output .= "Vehicle: " . $vehicle['full_name'] . "\n";
    } else {
        // Fall back to make/model combination
        if (!empty($vehicle['make'])) {
            $output .= "Make: " . $vehicle['make'] . "\n";
        }
        if (!empty($vehicle['model'])) {
            $output .= "Model: " . $vehicle['model'] . "\n";
        }
    }
    
    // Only show fields that have actual values
    if (!empty($vehicle['year'])) {
        $output .= "Year: " . $vehicle['year'] . "\n";
    }
    if (!empty($vehicle['type'])) {
        $output .= "Type: " . $vehicle['type'] . "\n";
    }
    if (!empty($vehicle['condition'])) {
        $output .= "Condition: " . $vehicle['condition'] . "\n";
    }
    if (!empty($vehicle['color'])) {
        $output .= "Color: " . $vehicle['color'] . "\n";
    }
    if (!empty($vehicle['vin'])) {
        $output .= "VIN: " . $vehicle['vin'] . "\n";
    }
    if (!empty($vehicle['specifications'])) {
        $output .= "Specifications: " . $vehicle['specifications'] . "\n";
    }
    if (!empty($vehicle['price'])) {
        $output .= "Price: " . $vehicle['price'] . "\n";
    }
    
    $output .= "\n";
}

// Messages Section
if (isset($data['messages']) && is_array($data['messages'])) {
    $output .= "MESSAGES CONVERSATION\n";
    $output .= "====================\n";
    
    foreach ($data['messages'] as $message) {
        if (isset($message['time'])) {
            $output .= "[" . $message['time'] . "] ";
        }
        if (isset($message['text'])) {
            $output .= $message['text'] . "\n";
        }
    }
    $output .= "\n";
}

// Additional Information
$additionalKeys = array_diff(array_keys($data), [
    'contact', 'contact_info', 'shipment', 'shipping', 'vehicle', 
    'vehicle_details', 'vehicle_info', 'vehicle_listing', 'messages'
]);

if (!empty($additionalKeys)) {
    $output .= "ADDITIONAL INFORMATION\n";
    $output .= "======================\n";
    
    foreach ($additionalKeys as $key) {
        $label = ucwords(str_replace('_', ' ', $key));
        $output .= $label . ": " . formatValue($data[$key]) . "\n";
    }
}

echo trim($output);
@endphp</pre>
            </div>
        @else
            <div class="text-center text-gray-500 dark:text-gray-400 py-8">
                <p>No extracted data available.</p>
            </div>
        @endif
    </div>

    <!-- Raw JSON (collapsible) -->
    <div class="border-t pt-4">
        <details class="group">
            <summary class="flex cursor-pointer items-center justify-between rounded-lg bg-gray-50 dark:bg-gray-800 p-4 text-gray-900 dark:text-gray-100">
                <h5 class="font-medium">Raw JSON Data</h5>
                <svg class="h-5 w-5 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </summary>
            <div class="mt-4 rounded-lg bg-gray-100 dark:bg-gray-800 p-4 border border-gray-300 dark:border-gray-600">
                <pre class="text-xs font-mono text-gray-900 dark:text-green-400 overflow-x-auto whitespace-pre-wrap"><code>@php
$json = $extraction->extracted_data ?? $extraction->raw_json;

// Ensure it's a string for display
if (!is_string($json)) {
    $json = json_encode($json, JSON_PRETTY_PRINT);
}

// Pretty format the JSON
$decodedJson = json_decode($json, true);
if ($decodedJson !== null) {
    $json = json_encode($decodedJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

echo htmlspecialchars($json);
@endphp</code></pre>
            </div>
        </details>
    </div>
</div>

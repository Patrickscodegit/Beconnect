@extends('layouts.app')

@section('content')
<div class="schedule-page">
    <div class="page-header">
        <h1>Shipping Schedules</h1>
        <p class="page-description">Find and update shipping schedules for your Robaws offers</p>
    </div>
    
    <div class="schedule-filters">
        <div class="filter-row">
            <div class="filter-group">
                <label for="pol">Port of Loading (POL):</label>
                <select name="pol" id="pol" class="form-select">
                    <option value="">Select POL</option>
                    @foreach($ports as $port)
                        <option value="{{ $port->code }}" {{ $pol == $port->code ? 'selected' : '' }}>
                            {{ $port->name }} ({{ $port->code }})
                        </option>
                    @endforeach
                </select>
            </div>
            
            <div class="filter-group">
                <label for="pod">Port of Discharge (POD):</label>
                <select name="pod" id="pod" class="form-select">
                    <option value="">Select POD</option>
                    @foreach($ports as $port)
                        <option value="{{ $port->code }}" {{ $pod == $port->code ? 'selected' : '' }}>
                            {{ $port->name }} ({{ $port->code }})
                        </option>
                    @endforeach
                </select>
            </div>
            
            <div class="filter-group">
                <label for="service_type">Service Type:</label>
                <select name="service_type" id="service_type" class="form-select">
                    <option value="">All Service Types</option>
                    <option value="RORO" {{ $serviceType == 'RORO' ? 'selected' : '' }}>RORO</option>
                    <option value="FCL" {{ $serviceType == 'FCL' ? 'selected' : '' }}>FCL</option>
                    <option value="LCL" {{ $serviceType == 'LCL' ? 'selected' : '' }}>LCL</option>
                    <option value="BREAKBULK" {{ $serviceType == 'BREAKBULK' ? 'selected' : '' }}>BREAKBULK</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="offer_id">Robaws Offer ID (Optional):</label>
                <input type="text" name="offer_id" id="offer_id" value="{{ $offerId }}" placeholder="e.g., 12345" class="form-control">
                <small class="help-text">Leave empty to just view schedules</small>
            </div>
            
            <div class="filter-group">
                <button id="search-schedules" class="btn btn-primary">Search Schedules</button>
            </div>
        </div>
    </div>
    
    <div class="schedule-results">
        <div class="loading-indicator" id="loading-indicator" style="display: none;">
            <div class="spinner"></div>
            <span>Searching schedules...</span>
        </div>
        
        <div class="no-results" id="no-results" style="display: none;">
            <p>No schedules found for the selected criteria.</p>
        </div>
        
        <div class="schedule-results-grid" id="schedule-results-grid">
            <!-- Dynamic content loaded via AJAX -->
        </div>
    </div>
</div>

<style>
.schedule-page {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    margin-bottom: 30px;
    text-align: center;
}

.page-header h1 {
    color: #2c3e50;
    margin-bottom: 10px;
}

.page-description {
    color: #7f8c8d;
    font-size: 16px;
}

.schedule-filters {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 30px;
}

.filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    align-items: end;
}

.filter-group {
    display: flex;
    flex-direction: column;
}

.filter-group label {
    font-weight: 600;
    margin-bottom: 5px;
    color: #2c3e50;
}

.form-select, .form-control {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.help-text {
    color: #6c757d;
    font-size: 12px;
    margin-top: 5px;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
}

.btn-primary {
    background-color: #007bff;
    color: white;
}

.btn-primary:hover {
    background-color: #0056b3;
}

.btn-secondary {
    background-color: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background-color: #545b62;
}

.btn-sm {
    padding: 5px 10px;
    font-size: 12px;
}

.loading-indicator {
    text-align: center;
    padding: 40px;
}

.spinner {
    border: 4px solid #f3f3f3;
    border-top: 4px solid #007bff;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
    margin: 0 auto 20px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.schedule-results-grid {
    display: grid;
    gap: 20px;
}

.carrier-group {
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 20px;
}

.carrier-group h3 {
    color: #2c3e50;
    margin-bottom: 10px;
    border-bottom: 2px solid #007bff;
    padding-bottom: 10px;
}

.carrier-info {
    margin-bottom: 20px;
    font-size: 14px;
    color: #6c757d;
}

.specialization, .service-types {
    display: inline-block;
    background: #e9ecef;
    padding: 4px 8px;
    border-radius: 4px;
    margin-right: 10px;
    margin-bottom: 5px;
}

.schedules-list {
    display: grid;
    gap: 15px;
}

.schedule-card {
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 15px;
    background: #f8f9fa;
}

.schedule-header {
    display: flex;
    justify-content: between;
    align-items: center;
    margin-bottom: 15px;
}

.schedule-header h4 {
    color: #2c3e50;
    margin: 0;
    flex: 1;
}

.frequency {
    background: #007bff;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}

.schedule-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 10px;
    margin-bottom: 15px;
}

.detail-row {
    display: flex;
    flex-direction: column;
}

.detail-row .label {
    font-weight: 600;
    color: #495057;
    font-size: 12px;
    margin-bottom: 2px;
}

.detail-row .value {
    color: #2c3e50;
    font-size: 14px;
}

.schedule-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.no-results {
    text-align: center;
    padding: 40px;
    color: #6c757d;
}

@media (max-width: 768px) {
    .filter-row {
        grid-template-columns: 1fr;
    }
    
    .schedule-details {
        grid-template-columns: 1fr;
    }
    
    .schedule-actions {
        flex-direction: column;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchButton = document.getElementById('search-schedules');
    const loadingIndicator = document.getElementById('loading-indicator');
    const noResults = document.getElementById('no-results');
    const resultsGrid = document.getElementById('schedule-results-grid');
    
    // Search schedules
    searchButton.addEventListener('click', function() {
        const pol = document.getElementById('pol').value;
        const pod = document.getElementById('pod').value;
        const serviceType = document.getElementById('service_type').value;
        const offerId = document.getElementById('offer_id').value;
        
        if (!pol || !pod) {
            alert('Please select both POL and POD');
            return;
        }
        
        // Show loading
        loadingIndicator.style.display = 'block';
        noResults.style.display = 'none';
        resultsGrid.innerHTML = '';
        
        fetch(`/schedules/search?pol=${pol}&pod=${pod}&service_type=${serviceType}&offer_id=${offerId}`)
            .then(response => response.json())
            .then(data => {
                loadingIndicator.style.display = 'none';
                displayScheduleResults(data, offerId);
            })
            .catch(error => {
                loadingIndicator.style.display = 'none';
                console.error('Error:', error);
                alert('Error searching schedules: ' + error.message);
            });
    });
    
    // Display schedule results
    function displayScheduleResults(data, offerId) {
        if (data.carriers && Object.keys(data.carriers).length > 0) {
            let html = '';
            
            Object.values(data.carriers).forEach(carrier => {
                html += `
                    <div class="carrier-group">
                        <h3>${carrier.name}</h3>
                        <div class="carrier-info">
                            <span class="specialization">${carrier.specialization ? (() => {
                                try {
                                    const spec = typeof carrier.specialization === 'string' ? JSON.parse(carrier.specialization) : carrier.specialization;
                                    return typeof spec === 'object' && spec !== null ? Object.keys(spec).filter(key => spec[key] === true).join(', ') : '';
                                } catch (e) {
                                    return '';
                                }
                            })() : ''}</span>
                            <span class="service-types">${carrier.service_types ? (() => {
                                try {
                                    const types = typeof carrier.service_types === 'string' ? JSON.parse(carrier.service_types) : carrier.service_types;
                                    return Array.isArray(types) ? types.join(', ') : '';
                                } catch (e) {
                                    return '';
                                }
                            })() : ''}</span>
                        </div>
                        
                        <div class="schedules-list">
                            ${carrier.schedules.map(schedule => `
                                <div class="schedule-card">
                                    <div class="schedule-header">
                                        <h4>${schedule.service_name}</h4>
                                        <span class="frequency">${schedule.frequency_per_month}x/month</span>
                                    </div>
                                    
                                    <div class="schedule-details">
                                        <div class="detail-row">
                                            <span class="label">Transit Time:</span>
                                            <span class="value">${schedule.transit_days} days</span>
                                        </div>
                                        
                                        <div class="detail-row">
                                            <span class="label">Next Sailing:</span>
                                            <span class="value">${schedule.next_sailing_date}</span>
                                        </div>
                                        
                                        <div class="detail-row">
                                            <span class="label">Vessel:</span>
                                            <span class="value">${schedule.vessel_name || 'TBA'}</span>
                                        </div>
                                        
                                        <div class="detail-row">
                                            <span class="label">ETS:</span>
                                            <span class="value">${schedule.ets_pol || 'TBA'}</span>
                                        </div>
                                        
                                        <div class="detail-row">
                                            <span class="label">ETA:</span>
                                            <span class="value">${schedule.eta_pod || 'TBA'}</span>
                                        </div>
                                    </div>
                                    
                                    <div class="schedule-actions">
                                        <button class="btn btn-sm btn-secondary copy-schedule" 
                                                data-schedule='${JSON.stringify(schedule)}'>
                                            Copy to Clipboard
                                        </button>
                                        
                                        <button class="btn btn-sm btn-primary update-offer" 
                                                data-schedule='${JSON.stringify(schedule)}'
                                                data-offer-id="${offerId}">
                                            Update Robaws Offer
                                        </button>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            });
            
            resultsGrid.innerHTML = html;
        } else {
            noResults.style.display = 'block';
        }
    }
    
    // Copy to clipboard
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('copy-schedule')) {
            const schedule = JSON.parse(e.target.dataset.schedule);
            const scheduleText = `
Service: ${schedule.service_name}
Frequency: ${schedule.frequency_per_month}x/month
Transit Time: ${schedule.transit_days} days
Next Sailing: ${schedule.next_sailing_date}
Vessel: ${schedule.vessel_name || 'TBA'}
ETS: ${schedule.ets_pol || 'TBA'}
ETA: ${schedule.eta_pod || 'TBA'}
            `.trim();
            
            navigator.clipboard.writeText(scheduleText).then(function() {
                alert('Schedule copied to clipboard!');
            });
        }
    });
    
    // Update offer
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('update-offer')) {
            const schedule = JSON.parse(e.target.dataset.schedule);
            const offerId = e.target.dataset.offerId;
            
            if (!offerId || offerId === 'null') {
                alert('Please enter a Robaws Offer ID in the filter section.');
                return;
            }
            
            if (!confirm(`Update Robaws Offer ${offerId} with schedule "${schedule.service_name}"?`)) {
                return;
            }
            
            fetch('/schedules/update-offer', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    offer_id: offerId,
                    schedule_id: schedule.id
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Offer ${offerId} updated successfully with schedule "${data.schedule}"`);
                } else {
                    alert('Error updating offer: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error updating offer: ' + error.message);
            });
        }
    });
});
</script>
@endsection

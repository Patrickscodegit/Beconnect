<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Quotation Request</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f3f4f6;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 40px 0;">
                <table role="presentation" style="width: 600px; max-width: 100%; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); padding: 30px; text-align: center; border-radius: 8px 8px 0 0;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 24px;">
                                🔔 New Quotation Request
                            </h1>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 30px;">
                            
                            <!-- Request Number -->
                            <div style="background-color: #eff6ff; border-left: 4px solid #3b82f6; padding: 15px; margin-bottom: 25px; border-radius: 4px;">
                                <p style="margin: 0; font-size: 14px; color: #1e40af;">
                                    <strong>Request Number:</strong> {{ $quotation->request_number }}
                                </p>
                                <p style="margin: 5px 0 0 0; font-size: 12px; color: #6b7280;">
                                    Submitted: {{ $quotation->created_at->format('F j, Y \a\t g:i A') }}
                                </p>
                            </div>
                            
                            <!-- Customer Information -->
                            <div style="background-color: #f9fafb; padding: 20px; margin-bottom: 20px; border-radius: 6px;">
                                <h2 style="margin: 0 0 15px 0; font-size: 16px; color: #111827; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px;">
                                    📋 Customer Information
                                </h2>
                                <table style="width: 100%; border-collapse: collapse;">
                                    <tr>
                                        <td style="padding: 6px 0; color: #6b7280; font-size: 14px; width: 120px;"><strong>Name:</strong></td>
                                        <td style="padding: 6px 0; color: #111827; font-size: 14px;">{{ $quotation->contact_name }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 6px 0; color: #6b7280; font-size: 14px;"><strong>Email:</strong></td>
                                        <td style="padding: 6px 0; color: #111827; font-size: 14px;">
                                            <a href="mailto:{{ $quotation->contact_email }}" style="color: #3b82f6; text-decoration: none;">
                                                {{ $quotation->contact_email }}
                                            </a>
                                        </td>
                                    </tr>
                                    @if($quotation->contact_phone)
                                    <tr>
                                        <td style="padding: 6px 0; color: #6b7280; font-size: 14px;"><strong>Phone:</strong></td>
                                        <td style="padding: 6px 0; color: #111827; font-size: 14px;">{{ $quotation->contact_phone }}</td>
                                    </tr>
                                    @endif
                                    @if($quotation->client_name)
                                    <tr>
                                        <td style="padding: 6px 0; color: #6b7280; font-size: 14px;"><strong>Company:</strong></td>
                                        <td style="padding: 6px 0; color: #111827; font-size: 14px;">{{ $quotation->client_name }}</td>
                                    </tr>
                                    @endif
                                    <tr>
                                        <td style="padding: 6px 0; color: #6b7280; font-size: 14px;"><strong>Source:</strong></td>
                                        <td style="padding: 6px 0; font-size: 14px;">
                                            <span style="display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 12px; font-weight: 600;
                                                @if($quotation->source === 'customer') background-color: #dbeafe; color: #1e40af;
                                                @elseif($quotation->source === 'prospect') background-color: #fef3c7; color: #92400e;
                                                @else background-color: #f3e8ff; color: #6b21a8;
                                                @endif">
                                                {{ ucfirst($quotation->source) }}
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            
                            <!-- Shipment Details -->
                            <div style="background-color: #f9fafb; padding: 20px; margin-bottom: 20px; border-radius: 6px;">
                                <h2 style="margin: 0 0 15px 0; font-size: 16px; color: #111827; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px;">
                                    🚢 Shipment Details
                                </h2>
                                <table style="width: 100%; border-collapse: collapse;">
                                    <tr>
                                        <td style="padding: 6px 0; color: #6b7280; font-size: 14px; width: 120px;"><strong>Route:</strong></td>
                                        <td style="padding: 6px 0; color: #111827; font-size: 14px; font-weight: 600;">
                                            {{ $quotation->pol ?? 'N/A' }} → {{ $quotation->pod ?? 'N/A' }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 6px 0; color: #6b7280; font-size: 14px;"><strong>Service:</strong></td>
                                        <td style="padding: 6px 0; color: #111827; font-size: 14px;">{{ $serviceType }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 6px 0; color: #6b7280; font-size: 14px;"><strong>Direction:</strong></td>
                                        <td style="padding: 6px 0; color: #111827; font-size: 14px;">{{ ucfirst($quotation->trade_direction) }}</td>
                                    </tr>
                                    @if($quotation->commodity_type)
                                    <tr>
                                        <td style="padding: 6px 0; color: #6b7280; font-size: 14px;"><strong>Commodity:</strong></td>
                                        <td style="padding: 6px 0; color: #111827; font-size: 14px;">{{ $quotation->commodity_type }}</td>
                                    </tr>
                                    @endif
                                </table>
                                
                                @if($quotation->cargo_description)
                                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e5e7eb;">
                                    <p style="margin: 0 0 5px 0; color: #6b7280; font-size: 13px; font-weight: 600;">Cargo Description:</p>
                                    <p style="margin: 0; color: #111827; font-size: 14px; line-height: 1.5;">
                                        {{ $quotation->cargo_description }}
                                    </p>
                                </div>
                                @endif
                            </div>
                            
                            <!-- Additional Information -->
                            @if($quotation->special_requirements || $quotation->preferred_departure_date)
                            <div style="background-color: #fef3c7; padding: 15px; margin-bottom: 25px; border-radius: 6px; border-left: 4px solid #f59e0b;">
                                <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #92400e;">📝 Additional Information</h3>
                                @if($quotation->special_requirements)
                                <p style="margin: 0 0 8px 0; color: #78350f; font-size: 13px;">
                                    <strong>Special Requirements:</strong> {{ $quotation->special_requirements }}
                                </p>
                                @endif
                                @if($quotation->preferred_departure_date)
                                <p style="margin: 0; color: #78350f; font-size: 13px;">
                                    <strong>Preferred Departure:</strong> {{ \Carbon\Carbon::parse($quotation->preferred_departure_date)->format('F j, Y') }}
                                </p>
                                @endif
                            </div>
                            @endif
                            
                            <!-- CTA Button -->
                            <div style="text-align: center; margin-top: 30px;">
                                <a href="{{ $adminLink }}" style="display: inline-block; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: #ffffff; padding: 14px 32px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 15px; box-shadow: 0 4px 6px rgba(59, 130, 246, 0.3);">
                                    🔍 View in Admin Panel
                                </a>
                            </div>
                            
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f9fafb; padding: 20px; text-align: center; border-radius: 0 0 8px 8px; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0; color: #6b7280; font-size: 12px;">
                                This is an automated notification from Belgaco Logistics Quotation System
                            </p>
                            <p style="margin: 5px 0 0 0; color: #9ca3af; font-size: 11px;">
                                © {{ date('Y') }} Belgaco Logistics. All rights reserved.
                            </p>
                        </td>
                    </tr>
                    
                </table>
            </td>
        </tr>
    </table>
</body>
</html>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Quotation is Ready</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f3f4f6;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 40px 0;">
                <table role="presentation" style="width: 600px; max-width: 100%; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); padding: 30px; text-align: center; border-radius: 8px 8px 0 0;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 24px;">
                                ✅ Your Quotation is Ready!
                            </h1>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 30px;">
                            
                            <!-- Greeting -->
                            <p style="margin: 0 0 20px 0; color: #111827; font-size: 15px; line-height: 1.6;">
                                Dear <strong>{{ $quotation->contact_name }}</strong>,
                            </p>
                            
                            <p style="margin: 0 0 25px 0; color: #374151; font-size: 15px; line-height: 1.6;">
                                We're pleased to provide you with a quotation for your shipment from 
                                <strong>{{ $quotation->pol }}</strong> to <strong>{{ $quotation->pod }}</strong>.
                            </p>
                            
                            <!-- Request Number -->
                            <div style="background-color: #eff6ff; border-left: 4px solid #3b82f6; padding: 15px; margin-bottom: 25px; border-radius: 4px;">
                                <p style="margin: 0; font-size: 14px; color: #1e40af;">
                                    <strong>Request Number:</strong> {{ $quotation->request_number }}
                                </p>
                            </div>
                            
                            <!-- Price Box -->
                            @if($quotation->total_incl_vat)
                            <div style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 2px solid #10b981; padding: 25px; margin: 25px 0; border-radius: 8px; text-align: center;">
                                <h2 style="margin: 0 0 10px 0; color: #059669; font-size: 18px; font-weight: 600;">
                                    Total Price
                                </h2>
                                <p style="margin: 0; font-size: 36px; font-weight: bold; color: #059669;">
                                    €{{ number_format($quotation->total_incl_vat, 2) }}
                                </p>
                                <p style="margin: 8px 0 0 0; font-size: 13px; color: #6b7280;">
                                    Including {{ $quotation->vat_rate ?? 21 }}% VAT
                                </p>
                                
                                @if($quotation->total_excl_vat && $quotation->discount_amount > 0)
                                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #a7f3d0;">
                                    <table style="width: 100%; max-width: 300px; margin: 0 auto; font-size: 13px; color: #374151;">
                                        <tr>
                                            <td style="padding: 3px 0; text-align: left;">Subtotal:</td>
                                            <td style="padding: 3px 0; text-align: right;">€{{ number_format($quotation->subtotal, 2) }}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 3px 0; text-align: left;">Discount ({{ $quotation->discount_percentage }}%):</td>
                                            <td style="padding: 3px 0; text-align: right; color: #059669;">-€{{ number_format($quotation->discount_amount, 2) }}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 3px 0; text-align: left;">Total (excl. VAT):</td>
                                            <td style="padding: 3px 0; text-align: right;">€{{ number_format($quotation->total_excl_vat, 2) }}</td>
                                        </tr>
                                    </table>
                                </div>
                                @endif
                            </div>
                            @endif
                            
                            <!-- Validity Period -->
                            @if($quotation->expires_at)
                            <div style="background-color: #fff7ed; padding: 15px; margin: 20px 0; border-radius: 6px; border-left: 4px solid #f59e0b;">
                                <p style="margin: 0; color: #92400e; font-size: 14px;">
                                    ⏰ <strong>Valid until:</strong> {{ $quotation->expires_at->format('F j, Y') }}
                                </p>
                                <p style="margin: 5px 0 0 0; color: #78350f; font-size: 12px;">
                                    Please review and respond before this date to secure this pricing
                                </p>
                            </div>
                            @endif
                            
                            <!-- Shipment Summary -->
                            <div style="background-color: #f9fafb; padding: 20px; margin: 20px 0; border-radius: 6px;">
                                <h3 style="margin: 0 0 12px 0; font-size: 15px; color: #111827; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px;">
                                    📦 Shipment Summary
                                </h3>
                                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                                    <tr>
                                        <td style="padding: 5px 0; color: #6b7280; width: 140px;">Route:</td>
                                        <td style="padding: 5px 0; color: #111827; font-weight: 600;">
                                            {{ $quotation->pol }} → {{ $quotation->pod }}
                                        </td>
                                    </tr>
                                    @if($quotation->service_type)
                                    <tr>
                                        <td style="padding: 5px 0; color: #6b7280;">Service Type:</td>
                                        <td style="padding: 5px 0; color: #111827;">
                                            @php
                                                $serviceType = config('quotation.service_types.' . $quotation->service_type);
                                                $serviceName = is_array($serviceType) ? $serviceType['name'] : ($serviceType ?: $quotation->service_type);
                                            @endphp
                                            {{ $serviceName }}
                                        </td>
                                    </tr>
                                    @endif
                                    @if($quotation->cargo_description)
                                    <tr>
                                        <td style="padding: 5px 0; color: #6b7280; vertical-align: top;">Cargo:</td>
                                        <td style="padding: 5px 0; color: #111827;">{{ $quotation->cargo_description }}</td>
                                    </tr>
                                    @endif
                                </table>
                            </div>
                            
                            <!-- CTA Buttons -->
                            <div style="text-align: center; margin: 30px 0;">
                                <table role="presentation" style="margin: 0 auto;">
                                    <tr>
                                        <td style="padding: 0 5px;">
                                            <a href="{{ $viewLink }}" style="display: inline-block; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px;">
                                                📄 View Full Quotation
                                            </a>
                                        </td>
                                        <td style="padding: 0 5px;">
                                            <a href="{{ $acceptLink }}" style="display: inline-block; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px;">
                                                ✓ Accept Quotation
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            
                            <!-- Contact Information -->
                            <div style="background-color: #f9fafb; padding: 20px; margin-top: 30px; border-radius: 6px; text-align: center;">
                                <p style="margin: 0 0 10px 0; color: #6b7280; font-size: 13px;">
                                    Questions about this quotation?
                                </p>
                                <p style="margin: 0; color: #111827; font-size: 14px;">
                                    <strong>Contact us:</strong>
                                    <a href="mailto:{{ config('mail.team_address', 'info@belgaco.be') }}" style="color: #3b82f6; text-decoration: none;">
                                        {{ config('mail.team_address', 'info@belgaco.be') }}
                                    </a>
                                </p>
                            </div>
                            
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f9fafb; padding: 20px; text-align: center; border-radius: 0 0 8px 8px; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0; color: #6b7280; font-size: 12px;">
                                Thank you for choosing Belgaco Logistics
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


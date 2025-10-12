<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation Status Update</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f3f4f6;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 40px 0;">
                <table role="presentation" style="width: 600px; max-width: 100%; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    
                    <!-- Header -->
                    <tr>
                        <td style="
                            @if($quotation->status === 'accepted')
                                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                            @elseif($quotation->status === 'rejected')
                                background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
                            @elseif($quotation->status === 'expired')
                                background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                            @else
                                background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
                            @endif
                            padding: 30px; text-align: center; border-radius: 8px 8px 0 0;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 24px;">
                                @if($quotation->status === 'accepted')
                                    ✅ Quotation Accepted
                                @elseif($quotation->status === 'rejected')
                                    ❌ Quotation Declined
                                @elseif($quotation->status === 'expired')
                                    ⏰ Quotation Expired
                                @else
                                    📋 Status Update
                                @endif
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
                            
                            <!-- Request Number -->
                            <div style="background-color: #eff6ff; border-left: 4px solid #3b82f6; padding: 15px; margin-bottom: 25px; border-radius: 4px;">
                                <p style="margin: 0; font-size: 14px; color: #1e40af;">
                                    <strong>Request Number:</strong> {{ $quotation->request_number }}
                                </p>
                            </div>
                            
                            <!-- Status Change Message -->
                            @if($quotation->status === 'accepted')
                                <div style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 2px solid #10b981; padding: 25px; margin: 25px 0; border-radius: 8px;">
                                    <h2 style="margin: 0 0 12px 0; color: #059669; font-size: 18px; font-weight: 600;">
                                        🎉 Great News!
                                    </h2>
                                    <p style="margin: 0; color: #047857; font-size: 15px; line-height: 1.6;">
                                        Your quotation has been accepted! We're excited to handle your shipment from 
                                        <strong>{{ $quotation->pol }}</strong> to <strong>{{ $quotation->pod }}</strong>.
                                    </p>
                                </div>
                                
                                <div style="background-color: #fef3c7; padding: 15px; margin: 20px 0; border-radius: 6px; border-left: 4px solid #f59e0b;">
                                    <h3 style="margin: 0 0 10px 0; color: #92400e; font-size: 15px;">📝 Next Steps</h3>
                                    <ul style="margin: 5px 0; padding-left: 20px; color: #78350f; font-size: 14px; line-height: 1.6;">
                                        <li>Our team will contact you shortly to confirm shipment details</li>
                                        <li>Please prepare all necessary shipping documents</li>
                                        <li>We'll send you booking confirmation and schedule information</li>
                                    </ul>
                                </div>
                            
                            @elseif($quotation->status === 'rejected')
                                <div style="background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%); border: 2px solid #ef4444; padding: 25px; margin: 25px 0; border-radius: 8px;">
                                    <h2 style="margin: 0 0 12px 0; color: #dc2626; font-size: 18px; font-weight: 600;">
                                        Quotation Declined
                                    </h2>
                                    <p style="margin: 0; color: #991b1b; font-size: 15px; line-height: 1.6;">
                                        We understand that this quotation didn't meet your requirements for the shipment from 
                                        <strong>{{ $quotation->pol }}</strong> to <strong>{{ $quotation->pod }}</strong>.
                                    </p>
                                </div>
                                
                                <div style="background-color: #eff6ff; padding: 15px; margin: 20px 0; border-radius: 6px; border-left: 4px solid #3b82f6;">
                                    <h3 style="margin: 0 0 10px 0; color: #1e40af; font-size: 15px;">💡 We'd Love to Help</h3>
                                    <p style="margin: 0; color: #1e3a8a; font-size: 14px; line-height: 1.6;">
                                        If you have any concerns or would like to discuss alternative options, 
                                        please don't hesitate to contact us. We're here to find the best solution for your shipping needs.
                                    </p>
                                </div>
                            
                            @elseif($quotation->status === 'expired')
                                <div style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border: 2px solid #f59e0b; padding: 25px; margin: 25px 0; border-radius: 8px;">
                                    <h2 style="margin: 0 0 12px 0; color: #d97706; font-size: 18px; font-weight: 600;">
                                        ⏰ Quotation Expired
                                    </h2>
                                    <p style="margin: 0; color: #92400e; font-size: 15px; line-height: 1.6;">
                                        Unfortunately, the quotation for your shipment from 
                                        <strong>{{ $quotation->pol }}</strong> to <strong>{{ $quotation->pod }}</strong> has expired.
                                    </p>
                                </div>
                                
                                <div style="background-color: #eff6ff; padding: 15px; margin: 20px 0; border-radius: 6px; border-left: 4px solid #3b82f6;">
                                    <h3 style="margin: 0 0 10px 0; color: #1e40af; font-size: 15px;">🔄 Request a New Quotation</h3>
                                    <p style="margin: 0; color: #1e3a8a; font-size: 14px; line-height: 1.6;">
                                        Pricing and availability may have changed. Please submit a new quotation request 
                                        to receive updated pricing for your shipment.
                                    </p>
                                </div>
                            
                            @else
                                <div style="background-color: #f9fafb; border: 2px solid #e5e7eb; padding: 25px; margin: 25px 0; border-radius: 8px;">
                                    <h2 style="margin: 0 0 12px 0; color: #111827; font-size: 18px; font-weight: 600;">
                                        Status Updated
                                    </h2>
                                    <p style="margin: 0; color: #374151; font-size: 15px; line-height: 1.6;">
                                        The status of your quotation for the shipment from 
                                        <strong>{{ $quotation->pol }}</strong> to <strong>{{ $quotation->pod }}</strong> 
                                        has been updated to: <strong>{{ ucfirst($quotation->status) }}</strong>
                                    </p>
                                </div>
                            @endif
                            
                            <!-- View Quotation Button -->
                            <div style="text-align: center; margin: 30px 0;">
                                <a href="{{ $viewLink }}" style="display: inline-block; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: #ffffff; padding: 14px 32px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 15px; box-shadow: 0 4px 6px rgba(59, 130, 246, 0.3);">
                                    📄 View Quotation Details
                                </a>
                            </div>
                            
                            <!-- Contact Information -->
                            <div style="background-color: #f9fafb; padding: 20px; margin-top: 30px; border-radius: 6px; text-align: center;">
                                <p style="margin: 0 0 10px 0; color: #6b7280; font-size: 13px;">
                                    Questions or concerns?
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


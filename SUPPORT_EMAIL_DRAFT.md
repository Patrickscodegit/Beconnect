
# Robaws Support Email Draft

**To:** support@robaws.be  
**Subject:** API Access Temporarily Blocked - Request for Unblock

---

**Dear Robaws Support Team,**

I am experiencing issues with API access for my account and need assistance removing a temporary block.

**Account Details:**
- Email: sales@truck-time.com
- Company: truck-time.com

**Issue:**
My account is currently blocked from accessing the Robaws API. All API endpoints return:
- Status: 401 Unauthorized
- Header: `X-Robaws-Unauthorized-Reason: temp-blocked`

**Technical Details:**
- Timestamp: 2025-08-26T13:49:54Z
- Server Version: 12a09a4ad8-master
- Endpoints tested: `/api/v2/metadata`, `/api/v2/clients`
- Authentication method: HTTP Basic Auth (as per documentation)

**What I'm trying to achieve:**
I'm developing a custom integration to automatically sync data from our document processing system to Robaws quotations. This will eliminate manual copy-paste workflows and improve accuracy.

**Request:**
Please remove the temporary API block from my account and confirm that API access is enabled. I have already:
1. ✅ Verified user permissions include Customers, Quotations, and Work orders
2. ✅ Confirmed password is correct
3. ✅ Used proper HTTP Basic Authentication

**Additional Information:**
If needed, I'm willing to create a dedicated API-only user for this integration. Please advise on best practices for API access setup.

Thank you for your assistance. I look forward to your prompt response.

**Best regards,**  
[Your Name]  
[Your Company]  
[Your Contact Information]

---

**Copy this email, customize the personal details, and send to support@robaws.be**

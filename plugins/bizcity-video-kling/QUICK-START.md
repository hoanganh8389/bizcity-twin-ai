# 🚀 BizCity Video Kling - Quick Start Guide

## 📦 Installation Complete

Plugin đã được cấu trúc lại theo chuẩn WAIC Workflow System.

## ✅ Changes Summary

### 1. File Structure Reorganized
```
✓ lib/helpers/kling_api.php → lib/kling_api.php
✓ assets/css/admin.css → assets/admin.css
✓ Removed empty folders
```

### 2. Workflow Actions Renamed (WAIC Pattern)
```
✓ kling_create_job.php → kl_create_job.php
✓ kling_poll_status.php → kl_poll_status.php  
✓ kling_fetch_video.php → kl_fetch_video.php
```

### 3. Code Refactored
```
✓ Class names: WaicAction_kl_* 
✓ Method: run() → getResults($taskId, $variables, $step)
✓ Return format: Standard WAIC array
✓ Added getVariables() and setVariables()
```

## 🎯 Next Steps

### Step 1: Check Plugin Activation
```
WordPress Admin → Plugins → MU Plugins
→ Look for "BizCity Video Kling v1.0.0"
```

### Step 2: Configure API Key
```
Tools → Video Kling
→ Enter PiAPI API Key
→ Click "Test API"
→ Click "Lưu Cấu Hình"
```

### Step 3: Verify Workflow Actions
```
Go to: WAIC Workflow Builder
→ Create New Workflow
→ Click "Add Action"
→ Search for "Kling"
→ You should see:
   • Kling - Create Job
   • Kling - Poll Status
   • Kling - Fetch Video
```

## 🧪 Quick Test Workflow

1. **Add "Kling - Create Job" node**
   - API Key: (leave blank to use settings)
   - Model: kling-v1
   - Task Type: image_to_video
   - Image URL: https://example.com/test-image.jpg
   - Prompt: "Dynamic video showing product"
   - Duration: 20
   - Aspect Ratio: 9:16
   - Job ID: test_{{timestamp}}

2. **Add "Kling - Poll Status" node**
   - Job ID: {{node#1.job_id}}
   - Delay: 15 seconds
   - Max Wait: 600 seconds

3. **Add "Kling - Fetch Video" node**
   - Job ID: {{node#1.job_id}}
   - Mode: media
   - Filename: test-video-{{timestamp}}.mp4

4. **Connect nodes**: 1 → 2 → 3

5. **Save and Run**

## 📋 Verification Checklist

- [ ] Plugin shows in MU Plugins list
- [ ] Settings page accessible
- [ ] API test connection works
- [ ] Actions appear in Workflow Builder
- [ ] Action names correct (Kling - *)
- [ ] Settings UI renders properly
- [ ] Variables show in {{node#...}} format
- [ ] Test workflow executes without errors

## ⚠️ Troubleshooting

### Actions don't appear in Workflow Builder

**Possible causes:**
- WAIC plugin not active
- Bootstrap not loading
- Class name mismatch

**Fix:**
```php
// Check WordPress debug.log for errors
tail -f wp-content/debug.log

// Or check browser console in Workflow Builder
```

### Settings don't save

**Check:**
- User has `manage_options` capability
- Nonce field present
- Option names registered correctly

### API calls fail

**Check:**
- API key valid at https://piapi.ai
- Endpoint URL correct
- SSL certificate valid
- Check logs: wp-content/bizcity-video-kling.log

## 📞 Support

- **Documentation**: See README.md
- **Examples**: Check examples/ folder
- **Logs**: wp-content/bizcity-video-kling.log
- **Contact**: support@bizcity.vn

---

**Ready to go! 🎉**

Plugin version: 1.0.0  
Date: 2026-02-09

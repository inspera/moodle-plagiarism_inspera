# Originality Plagiarism Plugin for Moodle

Originality is a plugin that integrates Moodle with the Inspera Originality plagiarism detection service.

* **Copyright:** (C) 2025 Inspera AS
* **License:** GNU GPL v3 or later

> **Note:** This plugin requires a subscription to the Inspera Originality API service. You must have a valid `Client ID` and `Institution ID` to use it.

## Features
This initial release (`1.0.0`) currently supports:
* **Activity Support:** Moodle Assignment (`mod_assign`).
* **Submission Types:** File submissions and Online Text.
* **Roles:** Distinct report links for Students (View Only) vs Teachers (Edit/Grade mode).

**Known Limitations:**
* Quiz and Forum activities are **not** yet supported in this version.
* Supported on Moodle 4.5+ (see `version.php` for details).

## Installation

### 1. Download
Download the zip file or clone the repository into your Moodle `plagiarism` directory.
The folder name **must** be `originality`.

```bash
cd /path/to/your/moodle/plagiarism/
git clone [https://github.com/your-repo/moodle-plagiarism_originality.git](https://github.com/your-repo/moodle-plagiarism_originality.git) originality
```

### 2. Install
1.  Log in to your Moodle site as an Administrator.
2.  Go to **Site administration > Notifications**.
3.  Follow the prompts to upgrade the database and install the plugin.

### 3. Enable Plagiarism API
**Important:** Moodle disables plagiarism plugins by default.
1.  Navigate to **Site administration > Advanced features**.
2.  Check the box for **Enable plagiarism plugins**.
3.  Save changes.

## Configuration

1.  Navigate to **Site administration > Plugins > Plagiarism > Originality**.
2.  Enter your API credentials:
    * **API URL:** The base URL for the Originality API.
    * **Client ID:** Your unique client identifier.
    * **Institution ID:** Your institution identifier.
3.  Configure global defaults. 

## Usage in Assignments

To enable Originality for a specific assignment:
1.  Edit the Assignment settings.
2.  Scroll down to the **Originality Plagiarism Plugin** section.
3.  Set **Enable Originality** to **Yes**.
4.  Configure optional settings:
    * **Show similarity reports to students:** Choose when students can see the report.
    * **Supported File Types:** Restrict which files are sent for analysis.

## Troubleshooting

* **Plugin settings not appearing**: Ensure "Enable plagiarism plugins" is checked in Advanced Features (Step 3 above).
* **"You are not authorized to submit"**: Check your `Client ID` and `Institution ID` in the plugin settings.
* **Report links missing**: The report is generated via a background task (cron). Ensure your Moodle cron is running frequently (recommended: every 1 minute).
* **Connection Tests Fail**: Verify your server firewall allows outbound connections to the Originality API URL.
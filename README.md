# fb-stat

Facebook group data crawler tool

#What it does
  This script dumps infos about posts in a specific given group.
  It saves that data into CVS file and (if required) it imports that data
  in a MySQL table automatically.
  
#How it works
  First, it send an http request to Facebook Graph API, then the response
  (given in JSON format) is parsed, and the data stored into CSV file.
  The response contains also a pointer to the next "page" of posts, so
  the script continues to parse the other page and so on until the end of
  the feed.
  
#Permissions
  To use this script you must provide a valid facebook user access token
  (you can genereate it, for example, using Graph API explorer).
  The permission required are:
  - **user-groups**, if using Graph API v2.3 or previous
  - **user-managed-groups**, if using for Graph API v2.4 or higher
  
  **IMPORTANT NOTE:**
  If you use Graph API v2.3 or previous, you can use an access token with
  user-groups permission to analyze groups, even secret, that **you are
  member of**.
  
  Unfortunately with API v2.4 the permission user-group was deprecated
  and you can only use user-managed-groups to analyze groups that **you
  are an __admin__ of**. 
  
#Informations stored
  Currently, the information dumped are:
  - Post unique ID
  - Post author (unique ID and name)
  - Post type (eg. state, photo, link ...)
  - Post creation date
  - Total count of likes to the post
  - Total of comments to the post
  - Author (unique ID and name) of the first post comment (if any)
  - Total count of each reaction\*
  
  \* **Reaction are available only with API v2.6 or higher**
    
#System requirements
  This script is designed do be ran in a unix-like system, anyway since
  PHP and MySQL can run under multiple OS, it maybe will work under
  Windows or other OS.

  This script was tested on a Rasperry Pi running raspian, with PHP 5.4.

#Progress
  This script saves data in real time into the CSV output file. It writes
  one line per post, so you can check in real time the number of post
  analyzed using, for example:
  ```wc -l out.csv```

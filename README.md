# DialogFlow2Alexa
A simple PHP script to convert an exported DialogFlow project to Alexa JSON.

Instructions:

From your DialogFlow project, click on Integrations -> Alexa -> Export For Alexa.

Download this project on a webserver that supports php, or head over to https://phlexchat.com/convert for a version I'm hosting for you.

On the page that loads, upload your .zip file, and enter the invocation name for your Skill.

Click the "upload" button.

Copy the returned JSON.

Paste into the JSON editor for your skill, completely replacing anything that's there.

Save, build...profit!!


# Notes

This has only been written for a single-language project currently, I will have to add support for multiple locales after I add it to my own agent.

# DialogFlow2Alexa
A simple PHP script to convert an exported DialogFlow project to Alexa JSON.

Instructions:

Export your project from dialogFlow, either using settings->Export (recommended), or integrations->alexa->export for alexa.

Download this project on a webserver that supports php, or head over to https://phlexchat.com/convert for a version I'm hosting for you.

On the page that loads, upload your .zip file in the appropriate form.

If using the "Export For Alexa" method, enter your Project's invocation name.

If using an agent export, select the language from the dropdown. (This has only been tested with US English)

Click the respective "upload" button.

Copy the returned JSON (Hover over the box for a button).

Paste into the JSON editor for your skill, completely replacing anything that's there.

Click "Save Project" and then "Build Project".

Correct any issues that are reported. (Hey man, nobody's perfect)

...profit!!


# Notes

This has only been written for a single-language project currently, I will have to add support for multiple locales after I add it to my own agent.

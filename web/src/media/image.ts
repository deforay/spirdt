/**
 * Getting a photograph down to something that can be sent.
 *
 * NOT THE SECURITY BOUNDARY. The server resizes everything it receives and
 * refuses what it cannot decode, because a screen can be bypassed and a device
 * that has been offline for a day sends whatever it was given. This is here
 * for the connection: a phone photograph is several megabytes, an audit
 * carries up to five per section, and the difference between three megabytes
 * and two hundred kilobytes is the difference between a queue that clears over
 * a district office's uplink and one that does not.
 *
 * Everything degrades to sending the original. A browser without
 * createImageBitmap or toBlob still gets to take a photograph, and the server
 * deals with the size — which is a worse outcome than resizing and a much
 * better one than a button that does nothing.
 */

/**
 * The long edge, in pixels.
 *
 * Enough to read a label on a shelf or a serial number on a machine, and about
 * a fifth of what a current phone camera produces. Everything these images are
 * for — recognising a room, showing what was on a bench, printing onto a
 * report — is comfortable well below it.
 */
export const MAX_EDGE = 1600

/** Visibly indistinguishable at that size, and a fraction of the bytes. */
export const QUALITY = 0.82

export async function resizedForUpload(file: File): Promise<Blob> {
    if (typeof createImageBitmap !== 'function') {
        return file
    }

    let bitmap: ImageBitmap

    try {
        // `imageOrientation` matters more than it looks: a photograph taken
        // with the phone on its side is stored upright with a rotation flag in
        // its EXIF, and a canvas that ignores that produces a sideways image
        // with no flag left to fix it. Decoding with the orientation applied
        // bakes it in the right way up.
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' })
    } catch {
        return file
    }

    const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height))
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(bitmap.width * scale)
    canvas.height = Math.round(bitmap.height * scale)

    const context = canvas.getContext('2d')

    if (context === null) {
        bitmap.close()

        return file
    }

    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
    bitmap.close()

    const encoded = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob(resolve, 'image/jpeg', QUALITY),
    )

    return encoded ?? file
}

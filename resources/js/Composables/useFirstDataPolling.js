import {onBeforeUnmount, onMounted, ref} from "vue";
import axios from "axios";

const POLL_INTERVAL_MS = 5000;
const TAKING_LONG_AFTER_MS = 3 * 60 * 1000;

export function useFirstDataPolling(onReceived = () => {}) {
    const received = ref(false);
    const takingLong = ref(false);

    let pollTimer = null;
    let takingLongTimer = null;

    const stop = () => {
        clearInterval(pollTimer);
        clearTimeout(takingLongTimer);
    };

    const check = async () => {
        if (document.hidden) {
            return;
        }

        try {
            const {data} = await axios.get(route('stats.status'));

            if (data.hasData) {
                stop();
                received.value = true;
                onReceived();
            }
        } catch {
            // A failed check is retried on the next tick.
        }
    };

    onMounted(() => {
        check();
        pollTimer = setInterval(check, POLL_INTERVAL_MS);
        takingLongTimer = setTimeout(() => takingLong.value = true, TAKING_LONG_AFTER_MS);
    });

    onBeforeUnmount(stop);

    return {received, takingLong};
}
